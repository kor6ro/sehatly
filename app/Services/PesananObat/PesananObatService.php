<?php

declare(strict_types=1);

namespace App\Services\PesananObat;

use App\Enums\PesananObatStatus;
use App\Models\Faskes;
use App\Models\Invoice;
use App\Models\Pasien;
use App\Models\PesananObat;
use App\Models\PesananObatTracking;
use App\Models\Resep;
use App\Models\ResepItem;
use App\Models\User;
use App\Services\Invoice\InvoiceService;
use App\Services\Pasien\PasienRecordAccess;
use App\Services\Resep\ResepStateMachine;
use App\Services\Resep\ResepVerifikasiService;
use App\Support\Dokumen\NomorDokumen;
use App\Support\Uang\Uang;
use App\Support\WaktuIndonesia;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Prescription checkout, the order read, and the order's status transitions.
 *
 * ## PRESCRIPTION-ONLY IS THE RULE, and the DDL says why
 *
 * `pesanan_obat.resep_id` is `BIGINT UNSIGNED NULL` (`:800`) and there is **no
 * `pesanan_obat_item` table anywhere among the 75** - the schema has exactly
 * one order table and no order line table, which is why `Faskes::pesananObat()`
 * and `Resep::pesananObat()` are the only two order relations and neither is a
 * line. Three consequences, and they are the whole of this class's shape:
 *
 * 1. **A prescription order takes its products from `resep_item`.** The order
 *    has no lines of its own, so the only thing it can mean by "what was
 *    bought" is "the items of the prescription named by `resep_id`".
 * 2. **An `obat_bebas` or `produk_kesehatan` order is unrepresentable.** Its
 *    `resep_id` would be NULL, it would have no line rows to record the
 *    products in, and `subtotal` could not be recomputed from anything stored.
 *    {@see pastikanTipeResep()} refuses both with a 422, so the refusal happens
 *    AT CREATION - no order row, no invoice, no tracking row is written.
 * 3. **`resep_id` is NOT NULL for every order this service writes**, even
 *    though the column is nullable, which is the application half of the same
 *    rule.
 *
 * ## Are PARTIAL baskets allowed when mixed? No, and it is not a policy choice
 *
 * There is no basket to be partial. A checkout is ONE prescription and its
 * EVERY `resep_item` row: the client cannot select a subset, because
 * `pesanan_obat` has no column or table in which a selection would be recorded,
 * and a partial fill would leave `subtotal` unrecomputable from stored lines -
 * which `docs/schema-notes.md` records as exactly the reason the column cannot
 * be trusted. A MIXED basket (some prescription items plus an over-the-counter
 * product) is not merely unsupported, it is unrepresentable: the OTC product
 * has no row anywhere in this schema to live in. So the order is all-or-nothing
 * over one prescription, and the only caller input that could express a subset
 * is prohibited on the request rather than ignored.
 *
 * ## A RACIKAN MAKES THE ORDER UNPRICEABLE, and that is a refusal
 *
 * `resep_item.obat_id` is `NULL` for a racikan (`:770`) and
 * `resep_item.harga_satuan` is `DECIMAL(12,2) NOT NULL DEFAULT 0` (`:778`) -
 * and `ResepService::siapkanItem()` writes exactly `0.00` for such a row,
 * because a mixture has no catalogue price to copy. `InvoiceService` refuses a
 * zero-priced line by design (`Uang::parse($nilai, $bolehNol: false)`), so an
 * order containing a racikan cannot be invoiced.
 *
 * Refusing it is therefore the honest answer, and it removes two special cases
 * at once: a racikan has no `obat_id`, so it also has no `apotek_stok` row and
 * could never be stock-checked. {@see pastikanTidakAdaRacikan()} says both
 * things in two messages on `resep_id`.
 *
 * ## THE LOCK ORDER IS FIXED, and it is what stops a deadlock
 *
 * Every checkout takes, in this order:
 *
 * 1. `SELECT ... FROM resep WHERE id = ? AND pasien_id = ? FOR UPDATE` - the
 *    prescription, so the status and the expiry it is judged against are a
 *    CURRENT read rather than a `REPEATABLE READ` snapshot. This is the
 *    `BookingService` idiom verbatim, for the same reason: a locking read
 *    always observes the latest committed version.
 * 2. `SELECT ... FROM apotek_stok WHERE apotek_id = ? AND obat_id = ? FOR
 *    UPDATE` for each item, **in ascending `obat_id` order** - see
 *    {@see ApotekStokService::kurangi()}.
 *
 * The `obat_id` ordering is not tidiness. Two checkouts of the same two drugs
 * whose `resep_item` rows are stored in opposite order would take the same two
 * `apotek_stok` rows in opposite orders, and InnoDB would detect the cycle and
 * roll one of them back with error 1213 - a spurious failure caused entirely by
 * row order. Sorting by `obat_id` makes every checkout of a given basket take
 * the locks in the same global order, so one of them waits instead.
 *
 * ## The money, and the three places the same number appears
 *
 * `subtotal` is RECOMPUTED as `harga_satuan * jumlah` with `Uang::kali()` and
 * is never read off `resep_item.subtotal`, because nothing in the schema checks
 * `subtotal = harga_satuan * jumlah` (`:778`-`:779`) and a stored figure nothing
 * maintains is a figure that can be wrong. `total` is `subtotal + biaya_kirim`.
 *
 * `pesanan_obat` has exactly three money columns - `subtotal`, `biaya_kirim`,
 * `total` (`:807`-`:809`) - and **no admin-fee and no discount column**, so the
 * order's `total` is goods plus shipping and nothing else. `invoice` has five
 * (`:942`-`:946`), so the invoice a promo or a payment method makes different is
 * a different figure by design, and a client renders the invoice. The two are
 * equal when no promo and no method is chosen, and a test asserts exactly that.
 *
 * Every amount is a decimal STRING, because every amount is a JSON string on the
 * wire and a `float` has already lost precision by the time PHP's parser hands
 * it over. See {@see Uang}.
 *
 * ## Why the stock is decremented HERE and not at payment
 *
 * The plan puts the decrement in the payment transaction (todo 45), and todo 45
 * is not in this tree: there is no payment service, no route and no webhook to
 * hang it on. {@see ApotekStokService::kurangi()} is therefore called from the
 * checkout, inside the same transaction that writes the order, so the unit is
 * RESERVED when the order is placed. That is a single writer of
 * `apotek_stok.jumlah_stok` in the whole application, which is the property
 * that matters: when todo 45's webhook lands it must call this same method and
 * must NOT decrement a second time, or the unit leaves the shelf twice and
 * nothing in the schema could detect it.
 *
 * There is no stock-movement ledger among the 75, so every change to
 * `jumlah_stok` is unauditable beyond `audit_log` and a cancelled order does
 * not return its unit. Both are recorded in `docs/schema-notes.md`.
 */
final class PesananObatService
{
    /**
     * Collision attempts for `nomor_pesanan` before giving up.
     *
     * `nomor_pesanan` is `VARCHAR(30) NOT NULL UNIQUE` (`:799`), so its
     * collision is a real MySQL 1062 no pre-check can see. The budget matches
     * `BookingService::NOMOR_PERCOBAAN_MAX` and `ResepService::PERCOBAAN_NOMOR_MAKS`.
     */
    public const PERCOBAAN_NOMOR_MAKS = 3;

    /**
     * `pesanan_obat` columns the caller may never set.
     *
     * Every one is derived from the prescription, the patient, the clock or a
     * generator, and each is `prohibited` on the request. A caller who could set
     * `pasien_id` would order somebody else's drugs; one who could set `subtotal`
     * or `total` would order at a price of their choosing; one who could set
     * `status` would create an order that is already delivered.
     *
     * Each member is a real `pesanan_obat` column, asserted against the parsed
     * DDL on every test run.
     *
     * ## THE COMPLEMENT IS {@see KOLOM_DARI_REQUEST}, and it is not a smaller set by accident
     *
     * `CheckoutResepRequest` merges this map LAST, so `prohibited` BEATS a
     * field's own rule. That ordering is what makes the complement dangerous: a
     * column listed here whose value the caller is supposed to be able to
     * CHOOSE is answered `"The <name> field is prohibited."` - no reason, no
     * named value, and a caller who read it learns nothing.
     *
     * Three columns had to be moved to the complement, and all three were found
     * by running the tests rather than by reading the code:
     *
     * - `tipe`, so a legal `obat_bebas` is refused by
     *   {@see pastikanTipeResep()} with a message naming the missing
     *   line-item table rather than by a bare prohibited-field error.
     * - `apotek_id`, because the spec's alternatives rule is USELESS if the
     *   caller cannot pick another pharmacy; it is a choice, not a derivation.
     * - `biaya_kirim`, because there is NO shipping-rate table among the 75 (the
     *   only `ongkir` in the whole file is the `gratis_ongkir` promo type at
     *   `:989`), so the charge is an INPUT and prohibiting it would pin every
     *   order at `0.00`.
     *
     * @var list<string>
     */
    public const KOLOM_MILIK_SISTEM = [
        'nomor_pesanan',
        'resep_id',
        'pasien_id',
        'alamat_kirim',
        'subtotal',
        'total',
        'status',
        'no_resi',
        'dibuat_at',
        'diubah_at',
    ];

    /**
     * `pesanan_obat` columns the caller MAY send, and which the service still
     * decides the meaning of.
     *
     * The exact complement of {@see KOLOM_MILIK_SISTEM} over the fifteen real
     * columns, and the test asserts the partition is total - so a column added
     * to the DDL later has to be classified on purpose rather than defaulting
     * into one side or the other.
     *
     * `tipe` is accepted and then refused unless it is
     * {@see TIPE_RESEP}. `apotek_id` is a choice between pharmacies, resolved
     * against the prescription when absent. `biaya_kirim` is an amount the
     * schema has no rate card to price, parsed as a decimal STRING.
     *
     * @var list<string>
     */
    public const KOLOM_DARI_REQUEST = [
        'apotek_id',
        'biaya_kirim',
        'kurir',
        'tipe',
    ];

    /**
     * The three `pesanan_obat.tipe` members, in the DDL's own order (`:803`).
     *
     * PUBLIC and asserted against the parsed DDL, because the refusal in
     * {@see pastikanTipeResep()} is only meaningful if the set of values it
     * refuses is the set the column declares: a seventh value would be neither
     * accepted nor named in the message, and the guard would read as complete
     * when it is not.
     *
     * @var list<string>
     */
    public const SEMUA_TIPE = ['resep_dokter', 'obat_bebas', 'produk_kesehatan'];

    /**
     * The one `tipe` this service writes.
     */
    public const TIPE_RESEP = 'resep_dokter';

    /**
     * The six `pesanan_obat.kurir` members, in the DDL's own order (`:805`).
     *
     * `kurir` is the only NULLABLE ENUM in this table's batch and it has no
     * default, so an order with no carrier chosen is a real stored state rather
     * than an omission. These are carrier NAMES, not references: there is no
     * courier table among the 75.
     *
     * @var list<string>
     */
    public const SEMUA_KURIR = ['internal', 'grab_express', 'gojek', 'jne', 'jnt', 'sicepat'];

    /**
     * The account types that may read ANY order.
     *
     * `apoteker` is the pharmacy queue - a pharmacist's job is to see orders they
     * did not place. `admin` and `superadmin` are the oversight accounts the
     * plan's other read surfaces grant, and both hold `pesanan.lihat` in
     * `RbacCatalog::ROLE_PERMISSIONS` without owning a `pasien` row.
     *
     * `dokter` is deliberately absent and so is `perawat`/`kurir`: `dokter` holds
     * no `pesanan.lihat` at all, so it is refused by the middleware before this
     * class runs, and listing it here would be dead code that reads as a grant.
     *
     * @var list<string>
     */
    public const TIPE_PESANAN = ['apoteker', 'admin', 'superadmin'];

    public function __construct(
        private readonly ApotekStokService $stok,
        private readonly InvoiceService $invoice,
        private readonly ResepVerifikasiService $verifikasi,
        private readonly PasienRecordAccess $pasien,
        private readonly NomorDokumen $nomor,
    ) {}

    /**
     * Place one prescription order for `$pasien`, as `$caller`.
     *
     * @param  array<string, mixed>  $data  validated `CheckoutResepRequest` payload
     *
     * @throws AccessDeniedHttpException|ModelNotFoundException  when the caller owns no
     *                                                          `pasien` row, or the
     *                                                          prescription is not theirs
     * @throws ValidationException|StokTidakCukupException        on any refusal
     */
    public function buat(User $caller, Pasien $pasien, int $resepId, array $data): PesananObat
    {
        // 0. WHAT MAY THIS CALLER DO AT ALL. `ownPasien` has already answered it
        //    in the controller, and re-deriving it here would be a second
        //    implementation of the same rule.
        $this->pastikanTipeResep($data['tipe'] ?? self::TIPE_RESEP);

        $apotekId = $this->apotekTerpilih($data['apotek_id'] ?? null, $resepId);

        return DB::transaction(function () use ($pasien, $resepId, $data, $apotekId): PesananObat {
            // 1. THE PRESCRIPTION, LOCKED. Scoped to the patient FIRST, so another
            //    patient's prescription is a 404 about the row rather than a
            //    403 about the caller - the split `PasienRecordAccess` documents
            //    and this route depends on. The lock is what makes the two rules
            //    below CURRENT reads: a plain read here would resolve against the
            //    transaction's own snapshot and could pass a prescription that a
            //    concurrent verification has just moved.
            $resep = Resep::query()
                ->whereBelongsTo($pasien)
                ->whereKey($resepId)
                ->lockForUpdate()
                ->firstOrFail();

            $resep->loadMissing('resepItem');

            // 2. STATE. `ResepVerifikasiService::siapDipenuhi()` is a METHOD
            //    rather than an `if` precisely so this rule cannot be skipped by a
            //    caller that forgot it; its 422 carries two messages on `status`
            //    because the caller breaks two rules at once. The DDL's own
            //    comment states the rule (`:785`).
            $this->verifikasi->siapDipenuhi($resep);

            // 3. EXPIRY. `berlaku_sampai` is a `DATE` (`:755`) and NOTHING in the
            //    schema reacts to it, so a lapsed prescription can still read
            //    `diverifikasi`; the rule is a PHP comparison and the delegate is
            //    `ResepStateMachine::kedaluwarsa()` rather than a second one.
            if (ResepStateMachine::kedaluwarsa($resep)) {
                $this->gagalKedaluwarsa($resep);
            }

            // 4. LINES. Read from the prescription, never from the request: the
            //    request cannot express a selection (see the class docblock) and
            //    `items` is prohibited on it.
            $items = $this->barisResep($resep);

            $this->pastikanTidakAdaRacikan($resep, $items);

            // 5. THE PHARMACY. Existence, type and active state are three
            //    separate checks with three different fixes, so they are three
            //    separate messages rather than one vague refusal.
            $apotek = $this->stok->apotek($apotekId);

            // 6. THE STOCK, reserved under the lock in ascending `obat_id` order.
            //    The SORT is load-bearing, not tidiness: two checkouts of the same
            //    two drugs whose `resep_item` rows are stored in opposite order
            //    would take the same two `apotek_stok` rows in opposite orders and
            //    InnoDB would detect the cycle and roll one of them back with
            //    error 1213. Sorting a COPY leaves the invoice lines in the
            //    prescription's own order, which is the order a reader expects.
            $permintaan = $items;
            usort(
                $permintaan,
                static fn (array $a, array $b): int => ((int) $a['obat_id']) <=> ((int) $b['obat_id']),
            );

            foreach ($permintaan as $item) {
                $this->stok->kurangi($apotekId, (int) $item['obat_id'], $item['jumlah'], $item['nama_obat']);
            }

            // 7. THE MONEY, recomputed rather than read off a column nothing
            //    maintains.
            $subtotal = $this->subtotal($items);
            $pengiriman = $this->pengiriman($data['biaya_kirim'] ?? null);
            $total = Uang::jumlah($subtotal, $pengiriman);

            $this->pastikanBatas($subtotal, $pengiriman, $total);

            // 8. THE ORDER.
            $pesanan = $this->tulisDenganNomorUnik($pasien, $resep, $apotek, $data, $subtotal, $pengiriman, $total);

            // 9. THE FIRST TRACKING ROW. `pesanan_obat_tracking` declares no
            //    `dibuat_at` and no `diubah_at` (`:819`-`:827`) - only `waktu`
            //    (`:825`) - so `waktu` IS the creation stamp and it is written
            //    explicitly rather than left to a column that does not exist.
            $this->tulisTracking($pesanan, PesananObatStatus::default(), 'Pesanan dibuat dan menunggu pembayaran.', null);

            // 10. THE INVOICE, through the one entry point every invoice in this
            //     application is minted through. `referensi_tipe` is
            //     `'pesanan_obat'`, whose model `PesananObat::class` is already
            //     in `InvoiceService::SUMBER_REFERENSI`, so the polymorphic
            //     reference resolves through the existing whitelist rather than
            //     a second map.
            $this->invoice->buat(
                'pesanan_obat',
                (int) $pesanan->getKey(),
                (int) $pasien->getKey(),
                array_map(
                    static fn (array $item): array => [
                        'harga_satuan' => $item['harga_satuan'],
                        'jumlah' => $item['jumlah'],
                    ],
                    $items,
                ),
                isset($data['metode_id']) ? (int) $data['metode_id'] : null,
                isset($data['kode_promo']) ? (string) $data['kode_promo'] : null,
                $pengiriman,
            );

            return $pesanan;
        });
    }

    /**
     * The order `$caller` may read, with its tracking trail.
     *
     * The tenant filter IS the query, so another patient's order is simply not
     * found and cannot be told apart from one that never existed. An account
     * with no `pasien` row and no pharmacy-or-oversight type is refused about
     * ITSELF, which discloses nothing about any order.
     *
     * @throws AccessDeniedHttpException|ModelNotFoundException
     */
    public function untukBaca(User $caller, int $id): PesananObat
    {
        $adalahFarmasi = in_array((string) $caller->tipe, self::TIPE_PESANAN, true);

        if ($adalahFarmasi) {
            return $this->muatan($id);
        }

        $pasien = $this->pasien->ownPasienOrNull($caller);

        if ($pasien === null) {
            throw new AccessDeniedHttpException('Endpoint ini hanya untuk akun pasien, apoteker, admin, atau superadmin.');
        }

        $pesanan = $this->pasien->pesananObatQuery($pasien)->whereKey($id)->first();

        if ($pesanan === null) {
            throw (new ModelNotFoundException)->setModel(PesananObat::class, [$id]);
        }

        return $this->muatan((int) $pesanan->getKey());
    }

    /**
     * Advance one order to `$ke`, and record the move in its tracking trail.
     *
     * The order's status comes from {@see PesananObatStateMachine} and from
     * nowhere else; the trail is APPENDED to, never rewritten, and the order's
     * current state is always read from `pesanan_obat.status` rather than
     * inferred from the last trail row - see that class's docblock for why the
     * trail's own `VARCHAR(100)` column cannot be the authority.
     *
     * @param  string|null  $noResi  honoured on the `sedang_dikirim` edge only
     *
     * @throws ModelNotFoundException|ValidationException
     */
    public function ubahStatus(int $id, string $ke, ?string $keterangan = null, ?string $lokasi = null, ?string $noResi = null): PesananObat
    {
        $mesin = app(PesananObatStateMachine::class);

        if (! $mesin->adalahStatus($ke)) {
            throw ValidationException::withMessages([
                'status' => [
                    'Status pesanan tidak dikenal.',
                    'Nilai yang diizinkan: '.implode(', ', PesananObatStatus::nilai()).'.',
                ],
            ]);
        }

        return DB::transaction(function () use ($id, $ke, $keterangan, $lokasi, $noResi, $mesin): PesananObat {
            // Locked for the same reason the prescription is: the transition map
            // is read from a CURRENT state, and a snapshot read could decide
            // against a state that has already moved.
            $pesanan = PesananObat::query()->whereKey($id)->lockForUpdate()->firstOrFail();

            $dari = (string) $pesanan->status;

            $mesin->pastikan($dari, $ke);

            $resi = $this->resiUntuk($dari, $ke, $noResi);

            $pesanan->status = $ke;
            $pesanan->no_resi = $resi ?? $pesanan->no_resi;
            $pesanan->save();

            $this->tulisTracking($pesanan, $ke, $keterangan, $lokasi);

            return $pesanan->refresh();
        });
    }

    /**
     * The shelf for one drug, plus the pharmacies that could supply it instead.
     *
     * A pure read: it takes no row lock, because a caller LOOKING at a shelf
     * must not block a concurrent checkout, and the authoritative answer is
     * re-taken under the lock by {@see ApotekStokService::kurangi()} at the
     * moment of use. What this endpoint does NOT promise is a reservation.
     */
    public function cekStok(int $obatId, ?int $apotekId, int $jumlah): array
    {
        $terpilih = null;

        if ($apotekId !== null) {
            // A bad pharmacy is a 422 here too, for the same reason it is at
            // checkout: nothing constrains the column to an `apotek`, and a
            // caller that named a hospital deserves to be told before being
            // shown its (empty) shelf.
            $apotek = $this->stok->apotek($apotekId);
            $baris = $this->stok->stok($apotekId, $obatId);

            $terpilih = [
                'apotek_id' => (int) $apotek->getKey(),
                'nama' => (string) $apotek->nama,
                'recorded' => $baris !== null,
                'jumlah_stok' => $baris === null ? 0 : (int) $baris->jumlah_stok,
                'stok_minimum' => $baris === null ? 0 : (int) $baris->stok_minimum,
                'harga_jual' => $baris === null ? '0.00' : Uang::normal((string) $baris->harga_jual),
                'kedaluwarsa' => $baris?->kedaluwarsa?->toDateString(),
                'cukup' => $baris !== null && (int) $baris->jumlah_stok >= $jumlah,
            ];
        }

        return [
            'obat_id' => $obatId,
            'jumlah_diminta' => $jumlah,
            'apotek' => $terpilih,
            'alternatif' => $this->stok->alternatif($obatId, $jumlah, $apotekId),
        ];
    }

    /**
     * THE PRESCRIPTION-ONLY REFUSAL, and it happens before anything is written.
     *
     * Both refused values are legal `pesanan_obat.tipe` members (`:803`), so a
     * database would accept the row; what the schema cannot accept is the
     * CONSEQUENCE, which is an order with no `resep_id`, no line rows and an
     * unrecomputable `subtotal`. Two messages, because the caller broke one rule
     * and the fix is a different surface (the prescription endpoint) rather than
     * a different value of the same field.
     *
     * @return never
     *
     * @throws ValidationException
     */
    private function pastikanTipeResep(mixed $tipe): void
    {
        $tipe = (string) $tipe;

        if ($tipe === self::TIPE_RESEP) {
            return;
        }

        throw ValidationException::withMessages([
            'tipe' => [
                'Hanya pesanan dengan resep dokter yang dapat dibuat lewat endpoint ini.',
                sprintf(
                    'Tipe "%s" tidak dapat diimplementasikan: pesanan_obat tidak memiliki tabel item (telemedicine_test.sql:797-817), '
                    .'sehingga tidak ada tempat untuk mencatat produk yang dibeli dan subtotal tidak dapat dihitung ulang dari baris.',
                    $tipe,
                ),
            ],
        ]);
    }

    /**
     * The pharmacy this order is placed with.
     *
     * The prescription's own `apotek_id` when the caller names none - the plan's
     * rule - and the caller's choice otherwise, so a patient can be told a
     * pharmacy has none and pick another. `resep.apotek_id` is NULLABLE (`:749`)
     * and `pesanan_obat.apotek_id` is `NOT NULL` (`:802`), so the case where
     * neither names one is real and is refused rather than guessed.
     */
    private function apotekTerpilih(mixed $diminta, int $resepId): int
    {
        if ($diminta !== null) {
            return (int) $diminta;
        }

        $dariResep = Resep::query()->whereKey($resepId)->value('apotek_id');

        if ($dariResep !== null) {
            return (int) $dariResep;
        }

        throw ValidationException::withMessages([
            'apotek_id' => [
                'Apotek tujuan wajib diisi.',
                'Resep ini tidak memiliki apotek_id, jadi tidak ada apotek yang dapat dipilih secara otomatis.',
            ],
        ]);
    }

    /**
     * The prescription's items, as the invoice lines and the stock demands.
     *
     * A racikan (`obat_id` NULL) keeps its `obat_id` key with a `null` value
     * rather than being filtered out, because {@see pastikanTidakAdaRacikan()}
     * has to be able to NAME it in its message; filtering first would lose the
     * fact and make the refusal say "no items".
     *
     * @return list<array{resep_item_id: int, obat_id: ?int, nama_obat: string, jumlah: int, harga_satuan: string}>
     */
    private function barisResep(Resep $resep): array
    {
        $baris = ResepItem::query()
            ->where('resep_id', $resep->getKey())
            ->orderBy('id')
            ->get();

        if ($baris->isEmpty()) {
            throw ValidationException::withMessages([
                'resep_id' => [
                    'Resep tidak memiliki item.',
                    'Pesanan obat diturunkan dari item resep, jadi resep tanpa item tidak dapat dipesan.',
                ],
            ]);
        }

        return $baris->map(static fn (ResepItem $item): array => [
            'resep_item_id' => (int) $item->getKey(),
            'obat_id' => $item->obat_id === null ? null : (int) $item->obat_id,
            // The SNAPSHOT at prescribing time (`:771`), not a live join: the
            // patient was shown this name and this is what they agreed to buy.
            'nama_obat' => (string) $item->nama_obat,
            'jumlah' => (int) $item->jumlah,
            'harga_satuan' => Uang::normal((string) $item->harga_satuan),
        ])->all();
    }

    /**
     * Refuse a prescription containing a racikan.
     *
     * The racikan's name, so the caller is told WHICH line and not merely that
     * one exists.
     *
     * @param  list<array{resep_item_id: int, obat_id: ?int, nama_obat: string, jumlah: int, harga_satuan: string}>  $items
     *
     * @return never
     *
     * @throws ValidationException
     */
    private function pastikanTidakAdaRacikan(Resep $resep, array $items): void
    {
        $racikan = [];

        foreach ($items as $item) {
            if ($item['obat_id'] === null) {
                $racikan[] = $item['nama_obat'];
            }
        }

        if ($racikan === []) {
            return;
        }

        throw ValidationException::withMessages([
            'resep_id' => [
                'Resep memiliki racikan yang tidak memiliki harga katalog.',
                sprintf(
                    'Item racikan (%s) tidak memiliki obat_id maupun harga, sehingga tidak dapat diinventarisasi menjadi invoice.',
                    implode(', ', $racikan),
                ),
            ],
        ]);
    }

    /**
     * `subtotal` recomputed from the line prices and quantities.
     *
     * `resep_item.subtotal` (`:779`) is NOT read: nothing in the schema checks
     * it against `harga_satuan * jumlah` (`:778`), so it is a figure no rule
     * maintains and a wrong one is silently storable.
     *
     * @param  list<array{harga_satuan: string, jumlah: int, ...}>  $items
     */
    private function subtotal(array $items): string
    {
        $subtotal = '0.00';

        foreach ($items as $item) {
            $harga = $item['harga_satuan'];

            if (Uang::nol($harga)) {
                // Unreachable while {@see pastikanTidakAdaRacikan()} holds, and
                // stated rather than assumed: a zero-priced line would make
                // `InvoiceService` refuse the whole invoice, so failing HERE
                // names the drug instead of surfacing as a 422 on
                // `lines.0.harga_satuan` from a different layer.
                throw ValidationException::withMessages([
                    'resep_id' => [
                        sprintf('Item "%s" tidak memiliki harga satuan.', $item['nama_obat']),
                        'Pesanan tidak dapat dihitung karena ada item bernilai nol.',
                    ],
                ]);
            }

            $subtotal = Uang::jumlah($subtotal, Uang::kali($harga, $item['jumlah']));
        }

        return $subtotal;
    }

    /**
     * The shipping charge, from the request, as a decimal string.
     *
     * `pesanan_obat.biaya_kirim` is `DECIMAL(12,2) NOT NULL DEFAULT 0` (`:808`),
     * so `0.00` is legal and means nothing to ship for. A float is REFUSED
     * rather than cast: `Uang::parse` turns a JSON number into a 422 naming the
     * field, which is a contract answer, where a cast would silently lose
     * precision on money.
     */
    private function pengiriman(mixed $nilai): string
    {
        try {
            return Uang::parse($nilai ?? '0.00', true, Uang::BATAS_DECIMAL_12_2);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['biaya_kirim' => [$e->getMessage()]]);
        }
    }

    /**
     * Refuse amounts `DECIMAL(12,2)` cannot hold.
     *
     * `subtotal`, `biaya_kirim` and `total` are all `DECIMAL(12,2)`
     * (`:807`-`:809`), whose ceiling is `9999999999.99` - a NARROWER bound than
     * `invoice`'s `DECIMAL(14,2)`, so an order can overflow where its invoice
     * would not. Truncating silently would charge the wrong amount.
     *
     * @return never
     */
    private function pastikanBatas(string $subtotal, string $pengiriman, string $total): void
    {
        foreach (['subtotal' => $subtotal, 'biaya_kirim' => $pengiriman, 'total' => $total] as $kolom => $nilai) {
            if (Uang::gt($nilai, Uang::BATAS_DECIMAL_12_2)) {
                throw ValidationException::withMessages([
                    $kolom => [sprintf('Nilai %s melebihi kapasitas kolom DECIMAL(12,2).', $kolom)],
                ]);
            }
        }
    }

    /**
     * Write the order, its `tipe`, its money and its address.
     *
     * The retry loop is INSIDE the transaction, exactly as `BookingService` and
     * `ResepService` do: a rolled-back attempt leaves no order behind, which is
     * what makes "a refused checkout writes nothing" observable. Only a genuine
     * duplicate-key collision on `nomor_pesanan` retries; anything else
     * propagates.
     *
     * `status` is WRITTEN rather than defaulted, for the reason
     * {@see PesananObatStatus::default()} gives.
     *
     * The document number's date part is the **CLINIC's** day,
     * {@see WaktuIndonesia::tanggal()}, not `Carbon::now()->toDateString()`. The
     * order is dated on the day the patient placed it, and `config/app.php` is
     * `UTC` while WIB is +07:00, so a UTC basis stamped every order placed
     * between 00:00 and 07:00 WIB with the previous day's date.
     *
     * @param  array<string, mixed>  $data
     */
    private function tulisDenganNomorUnik(
        Pasien $pasien,
        Resep $resep,
        Faskes $apotek,
        array $data,
        string $subtotal,
        string $pengiriman,
        string $total,
    ): PesananObat {
        $hariIni = WaktuIndonesia::tanggal();

        for ($percobaan = 1; $percobaan <= self::PERCOBAAN_NOMOR_MAKS; $percobaan++) {
            try {
                $pesanan = new PesananObat;
                $pesanan->nomor_pesanan = $this->nomor->berikutnya(
                    NomorDokumen::PREFIX_PESANAN,
                    $hariIni,
                );
                $pesanan->resep_id = $resep->getKey();
                $pesanan->pasien_id = $pasien->getKey();
                $pesanan->apotek_id = $apotek->getKey();
                $pesanan->tipe = self::TIPE_RESEP;
                $pesanan->alamat_kirim = $this->alamat($pasien, $data);
                $pesanan->kurir = $data['kurir'] ?? null;
                $pesanan->no_resi = null;
                $pesanan->subtotal = $subtotal;
                $pesanan->biaya_kirim = $pengiriman;
                $pesanan->total = $total;
                $pesanan->status = PesananObatStatus::default();
                $pesanan->save();

                return $pesanan->refresh();
            } catch (UniqueConstraintViolationException) {
                if ($percobaan === self::PERCOBAAN_NOMOR_MAKS) {
                    throw ValidationException::withMessages([
                        'nomor_pesanan' => ['Nomor pesanan tidak dapat dibuat. Silakan coba lagi.'],
                    ]);
                }
            }
        }

        throw ValidationException::withMessages([
            'nomor_pesanan' => ['Nomor pesanan tidak dapat dibuat. Silakan coba lagi.'],
        ]);
    }

    /**
     * The shipping address: the caller's own.
     *
     * `pasien.alamat_lengkap` is `TEXT NOT NULL` (`:234`) so there is always one,
     * and `pesanan_obat.alamat_kirim` is a SNAPSHOT of it (`:804`) - stored once
     * on the order, with no address table and no foreign key to re-read from at
     * dispatch. That is why the profile changing after checkout does not move a
     * parcel already placed, and the field is `prohibited` on the request
     * because the order's address is derived, not chosen.
     */
    private function alamat(Pasien $pasien, array $data): string
    {
        $dariRequest = trim((string) ($data['alamat_kirim'] ?? ''));

        return $dariRequest !== '' ? $dariRequest : (string) $pasien->alamat_lengkap;
    }

    /**
     * Append one tracking row.
     *
     * `pesanan_obat_tracking` has NO `dibuat_at` and NO `diubah_at`
     * (`:819`-`:827`): only `waktu DATETIME NOT NULL` (`:825`). The model is
     * therefore `$timestamps = false` and `waktu` is written here, from the
     * clock, as the row's own creation stamp - never left to a column that does
     * not exist and never defaulted to `CURRENT_TIMESTAMP`, which would make the
     * tracker's clock rather than the application's the record of when.
     *
     * `status` is a `VARCHAR(100)` (`:822`) and this method is the narrowing
     * described in {@see PesananObatStateMachine}: the value is always one of
     * the six `pesanan_obat.status` members, so the trail can never carry a
     * courier's own vocabulary.
     */
    private function tulisTracking(PesananObat $pesanan, string $status, ?string $keterangan, ?string $lokasi): PesananObatTracking
    {
        $baris = new PesananObatTracking;
        $baris->pesanan_obat_id = $pesanan->getKey();
        $baris->status = $status;
        $baris->keterangan = $keterangan;
        $baris->lokasi = $lokasi;
        $baris->waktu = Carbon::now();
        $baris->save();

        return $baris;
    }

    /**
     * The tracking number for this edge, or the one already stored.
     *
     * `no_resi VARCHAR(50) NULL` (`:806`) is free text, NOT unique, NOT indexed,
     * and nullable while `kurir` is nullable too - so a tracking number with no
     * courier and a courier with no tracking number are both representable. A
     * number supplied on any edge OTHER than `sedang_dikirim` is refused rather
     * than stored, because a parcel that has not shipped has no number and
     * storing one would put a courier's reference on a row describing an order
     * that has not left.
     *
     * @return never
     */
    private function resiUntuk(string $dari, string $ke, ?string $noResi): ?string
    {
        $resi = $noResi === null ? null : trim($noResi);

        if ($resi === null || $resi === '') {
            return null;
        }

        if ($ke === 'sedang_dikirim') {
            return $resi;
        }

        throw ValidationException::withMessages([
            'no_resi' => [
                'Nomor resi hanya dapat diisi saat status pesanan menjadi sedang_dikirim.',
                sprintf('Status saat ini "%s" dan tujuan "%s"; parcel belum dikirim.', $dari, $ke),
            ],
        ]);
    }

    /**
     * The 422 for a prescription past its validity.
     *
     * `berlaku_sampai` is a `DATE` (`:755`) whose own COMMENT names seven days
     * of validity, and NOTHING in the schema reacts to it, so the stored status
     * can still read `diverifikasi` weeks later. `ResepStateMachine::kedaluwarsa()`
     * is the ONE implementation of that comparison and it is inclusive - valid
     * through today means still valid today - so this method reports rather than
     * re-derives.
     *
     * @return never
     */
    private function gagalKedaluwarsa(Resep $resep): void
    {
        $sampai = $resep->berlaku_sampai;

        throw ValidationException::withMessages([
            'resep_id' => [
                'Resep sudah kedaluwarsa dan tidak dapat dipesan.',
                sprintf('Berlaku sampai %s; tanggal tersebut sudah lewat.', $sampai?->toDateString() ?? '-'),
            ],
        ]);
    }

    /**
     * One order with everything the detail publishes.
     */
    private function muatan(int $id): PesananObat
    {
        return PesananObat::query()
            ->whereKey($id)
            ->with(['pesananObatTracking', 'apotek', 'resep.resepItem', 'pasien.user'])
            ->firstOrFail();
    }
}
