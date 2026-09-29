<?php

declare(strict_types=1);

namespace App\Services\Resep;

use App\Enums\ResepStatus;
use App\Models\Dokter;
use App\Models\Konsultasi;
use App\Models\MasterObat;
use App\Models\Resep;
use App\Models\ResepItem;
use App\Models\User;
use App\Services\Konsultasi\KonsultasiAccess;
use App\Services\Obat\ObatInteraksiService;
use App\Services\Pasien\PasienRecordAccess;
use App\Services\SuratKeterangan\QrTokenGenerator;
use App\Support\Dokumen\NomorDokumen;
use App\Support\WaktuIndonesia;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Writes e-prescriptions: snapshot items, price them, warn, and record the
 * acknowledgement - without ever blocking on a warning.
 */
final class ResepService
{
    /**
     * Days of validity, from the DDL's own comment on `resep.berlaku_sampai`
     * (`telemedicine_test.sql:755`, `COMMENT 'E-resep berlaku 7 hari'`).
     *
     * NOTHING in the schema reacts to that date - no trigger, no generated
     * column, no event - so the window is a PHP invariant made here: the
     * service writes `tanggal_resep` from the server clock and derives the
     * expiry from it by exactly this constant, and both columns are
     * `prohibited` on the request. Todo 40 owns the read side (the
     * `is_kedaluwarsa` flag); this todo writes the column and does not
     * enforce it, because there is no read of a prescription on this surface.
     */
    public const BERLAKU_SAMPAI_HARI = 7;

    /**
     * Collision attempts for `nomor_resep` before giving up.
     *
     * `nomor_resep` is `VARCHAR(30) NOT NULL UNIQUE` (`:744`), so its
     * collision is a real MySQL 1062 no pre-check can see. The budget matches
     * `BookingService::NOMOR_PERCOBAAN_MAX`.
     */
    public const PERCOBAAN_NOMOR_MAKS = 3;

    /**
     * `resep` columns the caller may never set.
     *
     * Every one is written from the consultation, the caller, the clock or a
     * generator. A doctor who could set `pasien_id` would prescribe to somebody
     * else; one who could set `qr_token` would point a prescription's QR at
     * another document; one who could set `berlaku_sampai` would write a
     * year-long prescription. Each member is a real `resep` column, asserted
     * against the parsed DDL on every test run.
     *
     * @var list<string>
     */
    public const KOLOM_MILIK_SISTEM = [
        'pasien_id',
        'dokter_id',
        'konsultasi_id',
        'rekam_medis_id',
        'apotek_id',
        'nomor_resep',
        'qr_token',
        'tipe',
        'status',
        'tanggal_resep',
        'berlaku_sampai',
        'is_iter',
        'jumlah_iter',
    ];

    /**
     * `resep_item` columns the caller may never set: computed from the
     * catalogue and the quantity, or owned by the pharmacist.
     *
     * @var list<string>
     */
    public const KOLOM_ITEM_MILIK_SISTEM = [
        'harga_satuan',
        'subtotal',
        'catatan_apoteker',
    ];

    public function __construct(
        private readonly KonsultasiAccess $akses,
        private readonly PasienRecordAccess $pasien,
        private readonly ObatInteraksiService $interaksi,
        private readonly QrTokenGenerator $token,
        private readonly NomorDokumen $nomor,
    ) {}

    /**
     * Write one prescription off a consultation the caller owns as doctor.
     *
     * Ownership is `KonsultasiAccess::untukDokter()`: a doctor-less account is
     * 403 about the caller, another doctor's consultation is 404 about the
     * row. Warnings are computed BEFORE the write and returned WITH it - a
     * dangerous pair is a 201 with a populated payload, never a refusal. The
     * only refusal on this surface is the missing acknowledgement note, and
     * it leaves zero rows behind.
     *
     * @param array<string, mixed> $data validated `StoreResepRequest` payload
     * @return array{resep: Resep, warning: list<array<string, mixed>>, warning_grup: array<string, list<array<string, mixed>>>, diminta: bool, catatan: ?string}
     */
    public function buat(User $caller, int $konsultasiId, array $data): array
    {
        $sesi = $this->akses->untukDokter($caller, $konsultasiId);
        $dokter = $this->pasien->ownDokterOrFail($caller);

        $items = $this->siapkanItem($data['items'] ?? []);

        $peringatan = $this->peringatan((int) $sesi->pasien_id, $items);

        $diminta = $this->interaksi->wajibCatatanDokter($peringatan);
        $catatan = $this->catatan($data['catatan_dodio'] ?? null, $diminta);

        $resep = $this->tulisDenganNomorUnik($sesi, $dokter, $items, $catatan);
        $resep->loadMissing('resepItem');

        $grup = [];
        foreach (ObatInteraksiService::SUMBER as $sumber) {
            $grup[$sumber] = array_values(array_filter(
                $peringatan,
                static fn (array $w): bool => ($w['sumber'] ?? null) === $sumber,
            ));
        }

        return [
            'resep' => $resep,
            'warning' => $peringatan,
            'warning_grup' => $grup,
            'diminta' => $diminta,
            'catatan' => $catatan,
        ];
    }

    /**
     * Validate every item against the catalogue and expand it into a storable
     * row, collecting EVERY violation before throwing.
     *
     * Two shapes: a catalogue item names `obat_id` (which must exist and be
     * `status_aktif = 1`, checked here so a bad id is a 422 naming the field
     * rather than a MySQL 1452 the envelope renders as a sanitised 500) and a
     * racikan names `nama_obat` with `is_racikan: true` (`obat_id` NULL,
     * `:770`). A racikan has no catalogue row, so it has no id to pair with
     * and no price to copy: `harga_satuan` and `subtotal` are 0, which the
     * `NOT NULL DEFAULT 0` columns (`:778`-`:779`) accept honestly.
     *
     * @param array<int, array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function siapkanItem(array $items): array
    {
        $katalog = $this->katalog($items);
        $siap = [];
        $galat = [];

        foreach (array_values($items) as $i => $item) {
            $obatId = $item['obat_id'] ?? null;

            // `is_racikan` is a DECLARATION and is checked against the shape it
            // describes, because the two are the same statement: `obat_id` NULL
            // *means* racikan (`:770`), so a catalogue drug that also claims to
            // be a racikan, and a racikan that denies being one, are both
            // contradictions. Deriving the column silently would turn both
            // into a 201 whose stored rows disagree with the request, which is
            // the worst available answer - the caller is told it succeeded and
            // gets something it did not ask for.
            $galatRacikan = $this->cekRacikan($obatId, $item['is_racikan'] ?? null);

            if ($galatRacikan !== null) {
                $galat['items'][$i]['is_racikan'][] = $galatRacikan;
                continue;
            }

            if ($obatId !== null) {
                $obat = $katalog[(int) $obatId] ?? null;

                if ($obat === null) {
                    $galat['items'][$i]['obat_id'][] = 'Obat tidak ditemukan.';
                    continue;
                }

                if (! (bool) $obat->status_aktif) {
                    $galat['items'][$i]['obat_id'][] = 'Obat tidak aktif.';
                    continue;
                }

                $harga = (string) $obat->harga_jual;
                $jumlah = (int) ($item['jumlah'] ?? 0);

                $siap[] = [
                    'obat_id' => (int) $obat->getKey(),
                    'nama_obat' => (string) $obat->nama_generik,
                    'kekuatan' => $item['kekuatan'] ?? $obat->kekuatan,
                    'aturan_pakai' => (string) ($item['aturan_pakai'] ?? ''),
                    'jumlah' => $jumlah,
                    'satuan' => $item['satuan'] ?? $obat->satuan,
                    'is_racikan' => false,
                    'racikan_nama' => $item['racikan_nama'] ?? null,
                    'harga_satuan' => $harga,
                    'subtotal' => $this->subtotal($harga, $jumlah),
                ];
                continue;
            }

            $nama = trim((string) ($item['nama_obat'] ?? ''));

            if ($nama === '') {
                // `is_racikan` is already true (the `cekRacikan` gate above
                // guaranteed it), so the item DID declare its shape and only
                // left the name out. Filed on the field that is actually
                // empty, which is the one the doctor can fill in.
                $galat['items'][$i]['nama_obat'][] = 'Nama racikan wajib diisi.';
                continue;
            }

            $jumlah = (int) ($item['jumlah'] ?? 0);

            $siap[] = [
                'obat_id' => null,
                'nama_obat' => $nama,
                'kekuatan' => $item['kekuatan'] ?? null,
                'aturan_pakai' => (string) ($item['aturan_pakai'] ?? ''),
                'jumlah' => $jumlah,
                'satuan' => $item['satuan'] ?? null,
                'is_racikan' => true,
                'racikan_nama' => $item['racikan_nama'] ?? $nama,
                'harga_satuan' => '0.00',
                'subtotal' => '0.00',
            ];
        }

        if ($galat !== []) {
            $this->gagal($galat);
        }

        return $siap;
    }

    /**
     * Throw the collected item errors WITHOUT losing the item indexes.
     *
     * `ValidationException::withMessages()` cannot be used here: it iterates
     * `Arr::wrap($value)` per top-level key and `MessageBag::add()`s each
     * element, which DISCARDS the inner numeric keys - an error filed for
     * `items[1]` renders at `errors.items.0`, pointing the doctor at the wrong
     * row (proven by a live dump: `{"items":[{"is_racikan":[...]}]}` for a
     * second-item error). Merging the nested map into the bag keeps every
     * index, so `errors.items.{i}.{field}` names the row that actually failed.
     *
     * @param array<string, mixed> $galat
     * @return never
     */
    private function gagal(array $galat): never
    {
        $validator = Validator::make([], []);

        $validator->errors()->merge($galat);

        throw new ValidationException($validator);
    }

    /**
     * Is `is_racikan` consistent with the rest of the item, and if not, why.
     *
     * Two shapes, one declaration each. The plan writes the racikan as
     * `{nama_obat, ..., is_racikan: true, racikan_nama}` and the catalogued one
     * as `{obat_id, ...}`, so the flag is part of the racikan's shape and is
     * optional on the catalogued one. An ABSENT flag on a racikan is refused
     * rather than inferred, for the same reason `obat_id` is not inferred: the
     * caller that omitted it is a caller that is not reading this contract,
     * and guessing for it produces a row nobody can explain.
     *
     * `false` on a catalogued item is accepted, because "not a racikan" is the
     * true statement about a catalogue drug and the column is derived anyway.
     * It is `true` on a catalogued item, or `false` on a racikan, that is a
     * contradiction - and an absent flag on a racikan is refused rather than
     * inferred. Every shape complaint is filed on `is_racikan`, the field
     * that failed to express the shape, so the envelope's `errors` map is
     * directly fillable.
     *
     * @return ?string the message, or null when the declaration is coherent
     */
    private function cekRacikan(mixed $obatId, mixed $diklaim): ?string
    {
        $ada = $obatId !== null;

        if ($ada && $diklaim === true) {
            return 'Obat katalog bukan racikan; racikan memakai nama_obat tanpa obat_id.';
        }

        if (! $ada && $diklaim !== true) {
            return $diklaim === null
                ? 'Item resep wajib menunjuk obat katalog atau berisi racikan.'
                : 'Racikan wajib dinyatakan is_racikan: true.';
        }

        return null;
    }

    /**
     * The catalogue rows for every `obat_id` in the request, keyed by id.
     *
     * @param array<int, array<string, mixed>> $items
     * @return array<int, MasterObat>
     */
    private function katalog(array $items): array
    {
        $ids = [];

        foreach ($items as $item) {
            if (($item['obat_id'] ?? null) !== null) {
                $ids[(int) $item['obat_id']] = true;
            }
        }

        if ($ids === []) {
            return [];
        }

        return MasterObat::query()->whereIn('id', array_keys($ids))->get()->keyBy('id')->all();
    }

    /**
     * Exact decimal arithmetic: both money columns are `DECIMAL(12,2)`.
     */
    private function subtotal(string $harga, int $jumlah): string
    {
        return bcmul($harga, (string) $jumlah, 2);
    }

    /**
     * The whole warning set for a prescription that does not exist yet.
     *
     * Catalogue ids go to all three engine checks; a racikan contributes its
     * `nama_obat` text to the allergy check only, because a mixture has no
     * single substance to pair up (`resep_item.obat_id` NULL, `:770`) while
     * its snapshot text is still a name worth matching.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function peringatan(int $pasienId, array $items): array
    {
        $ids = [];
        $nama = [];

        foreach ($items as $item) {
            if ($item['obat_id'] !== null) {
                $ids[] = (int) $item['obat_id'];
            } else {
                $nama[] = (string) $item['nama_obat'];
            }
        }

        if ($ids === [] && $nama === []) {
            return [];
        }

        $gabungan = [
            ...$this->interaksi->cekAntarObat($ids),
            ...$this->interaksi->cekRiwayatPasien($pasienId, $ids),
            ...$this->interaksi->cekAlergi($pasienId, [...$ids, ...$nama]),
        ];

        $unik = [];
        foreach ($gabungan as $w) {
            $unik[$w['kunci']] ??= $w;
        }

        return array_values($unik);
    }

    /**
     * The acknowledgement: `catatan_dodio` in, `resep.catatan_dokter` (`:753`)
     * out - the only column in the whole contract that can hold "this doctor
     * read the warning and proceeded", because there is no `resep_interaksi`
     * table and no acknowledgement column anywhere.
     *
     * REQUIRED exactly when the warning set demands it
     * (`wajibCatatanDokter()`), and a note that discards the warning would be
     * worse than none, so the warning set travels WITH the note in the
     * response rather than being consumed by it. A blank note under a demanded
     * one breaks two independent rules - it is blank, and a `kontraindikasi`
     * requires one - so both are reported on the same field, which is also
     * what proves the envelope preserves multiple messages per field.
     */
    private function catatan(mixed $mentah, bool $diminta): ?string
    {
        $catatan = trim((string) ($mentah ?? ''));

        if ($catatan === '') {
            if (! $diminta) {
                return null;
            }

            throw ValidationException::withMessages(['catatan_dodio' => [
                'Catatan pengakuan wajib diisi.',
                'Peringatan kontraindikasi memerlukan catatan dokter.',
            ]]);
        }

        return $catatan;
    }

    /**
     * Write the prescription and its items with collision-safe identifiers.
     *
     * The retry loop runs INSIDE the transaction, exactly as
     * `BookingService` and `SuratKeteranganService` do: a rolled-back attempt
     * leaves no `resep` and no `resep_item` row, which is what makes "a 422
     * writes nothing" observable. Only a genuine duplicate-key collision on
     * `nomor_resep` retries; anything else propagates.
     *
     * `qr_token` is `VARCHAR(100) NOT NULL` with no unique and no index
     * (`:758`), so the application checks before the write and redraws on a
     * taken value. The residual race (SELECT then INSERT with nothing
     * serialising them) is accepted and stated: a UNIQUE index would close it
     * and would also be permanent drift against read-only law.
     *
     * **The clock is the CLINIC's, and every value derived from it moves
     * together.** `$sekarang` is {@see WaktuIndonesia::now()}, the same instant
     * as `now()` on the Jakarta wall clock. It is read once so the three values
     * below cannot be built from three different days. It used to be
     * `Carbon::now()`, a UTC instant: for the seven hours from 00:00 to 07:00 WIB
     * a prescription written at 01:00 recorded a `tanggal_resep` seven hours into
     * the previous day and a `berlaku_sampai` **one calendar day short** - and
     * `berlaku_sampai` is a `DATE` whose own `COMMENT` (`:755`) counts "7 hari"
     * in days on paper, so a day short is a patient turned away a day early.
     * `docs/timezone-policy.md` classifies `tanggal_resep` as a wall clock
     * precisely because it is printed and dispensed against a local calendar date.
     *
     * @param list<array<string, mixed>> $items
     */
    private function tulisDenganNomorUnik(
        Konsultasi $sesi,
        Dokter $dokter,
        array $items,
        ?string $catatan,
    ): Resep {
        $sekarang = WaktuIndonesia::now();

        return DB::transaction(function () use ($sesi, $dokter, $items, $catatan, $sekarang): Resep {
            for ($percobaan = 1; $percobaan <= self::PERCOBAAN_NOMOR_MAKS; $percobaan++) {
                $token = $this->token->next();

                if (Resep::query()->where('qr_token', $token)->exists()) {
                    continue;
                }

                try {
                    $resep = new Resep;
                    $resep->nomor_resep = $this->nomor->berikutnya(
                        NomorDokumen::PREFIX_RESEP,
                        $sekarang->toDateString(),
                    );
                    $resep->konsultasi_id = $sesi->getKey();
                    $resep->rekam_medis_id = null;
                    $resep->pasien_id = $sesi->pasien_id;
                    $resep->dokter_id = $dokter->getKey();
                    $resep->apotek_id = null;
                    $resep->tipe = 'digital';
                    $resep->status = ResepStatus::default();
                    $resep->catatan_dokter = $catatan;
                    $resep->tanggal_resep = $sekarang;
                    $resep->berlaku_sampai = $sekarang->copy()->addDays(self::BERLAKU_SAMPAI_HARI)->toDateString();
                    $resep->is_iter = false;
                    $resep->jumlah_iter = 0;
                    $resep->qr_token = $token;
                    $resep->save();

                    foreach ($items as $siap) {
                        $baris = new ResepItem;
                        $baris->resep_id = $resep->getKey();
                        $baris->obat_id = $siap['obat_id'];
                        $baris->nama_obat = $siap['nama_obat'];
                        $baris->kekuatan = $siap['kekuatan'];
                        $baris->aturan_pakai = $siap['aturan_pakai'];
                        $baris->jumlah = $siap['jumlah'];
                        $baris->satuan = $siap['satuan'];
                        $baris->is_racikan = $siap['is_racikan'];
                        $baris->racikan_nama = $siap['racikan_nama'];
                        $baris->harga_satuan = $siap['harga_satuan'];
                        $baris->subtotal = $siap['subtotal'];
                        $baris->catatan_apoteker = null;
                        $baris->save();
                    }

                    return $resep->refresh();
                } catch (UniqueConstraintViolationException) {
                    if ($percobaan >= self::PERCOBAAN_NOMOR_MAKS) {
                        throw new RuntimeException('Nomor resep tidak dapat dibuat setelah mencoba batas maksimal.');
                    }
                }
            }

            throw new RuntimeException('Nomor resep tidak dapat dibuat setelah mencoba batas maksimal.');
        });
    }
}