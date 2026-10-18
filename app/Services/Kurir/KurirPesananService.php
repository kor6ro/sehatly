<?php

declare(strict_types=1);

namespace App\Services\Kurir;

use App\Enums\PesananObatStatus;
use App\Http\Resources\Kurir\KurirPesananResource;
use App\Models\PesananObat;
use App\Services\PesananObat\PesananObatService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The courier's delivery workflow: the queue, the pickup, the confirmation.
 *
 * Multi-portal plan section 5.2. `F46`'s order read (`GET /pesanan-obat/{id}`)
 * REFUSES the courier - `RbacCatalog` grants the courier `pesanan.antar` and
 * `pesanan.update` but deliberately NOT `pesanan.lihat`, and the plan's own 5.2
 * table says so in as many words - so the delivery surface is a separate, much
 * narrower pair of endpoints rather than a widened F46.
 *
 * ## What a courier may SEE, and why it stops where it stops
 *
 * A courier delivers a physical parcel. The facts that requires are the parcel's
 * identity (`nomor_pesanan`), where it came from (the pharmacy row), where it
 * goes (`alamat_kirim`, a free-text SNAPSHOT the patient wrote at checkout -
 * `PesananObatService::alamat()`, no address table and no re-read), which carrier
 * carries it and its tracking number. That is the whole of
 * {@see KurirPesananResource}.
 *
 * What is NOT published, on purpose: the money (`subtotal`/`biaya_kirim`/`total`),
 * the prescription link (`resep_id`) and with it the drug list, and the patient's
 * account (`pasien_id`). A delivery workflow that also displayed the invoice and
 * the clinical contents would hand every courier the two things the F46 refusal
 * exists to withhold, through a different path. `alamat_kirim` is the one piece
 * of patient-authored text in the payload because it is the delivery destination
 * itself - the courier cannot deliver a parcel they cannot address.
 *
 * ## The queue is the ACTIONABLE set, and the detail is one state wider
 *
 * `siap` (packed, awaiting pickup) and `sedang_dikirim` (in a courier's hands)
 * are the two states where a courier action exists, so they are the queue
 * ({@see antrean()}). `menunggu_pembayaran` and `diproses` have not reached the
 * counter yet and `dibatalkan` never will.
 *
 * The DETAIL ({@see cari()}) additionally serves `selesai`, so the screen a
 * courier lands on after confirming a delivery survives a reload instead of
 * 404ing on the very order it just closed. An order outside that visible set is
 * a 404 indistinguishable from one that never existed - the same answer F46's
 * tenant filter gives, for the same reason: the courier workflow must not become
 * an existence oracle for orders it has no business with.
 *
 * ## Both writes ride the ONE state machine, and neither invents an edge
 *
 * `ambil` is `siap -> sedang_dikirim` and `antar` is `sedang_dikirim -> selesai`,
 * the two edges {@see PesananObatStateMachine::TRANSISI} already legalises for
 * this role. The transition itself, the row lock, the tracking append and the
 * `no_resi` rule ("only on the `sedang_dikirim` edge") are ALL
 * {@see PesananObatService::ubahStatus()}'s - this service chooses the edge and
 * the default words, and nothing more. A second implementation of the map would
 * be the exact drift that class's docblock exists to prevent.
 *
 * ## No assignment column exists, and the queue is therefore shared
 *
 * `pesanan_obat` has NO `kurir_user_id` (`:797-:817`): the `kurir` column is a
 * six-value carrier-name ENUM, nullable, with no courier table behind it. So
 * "which courier owns this parcel" is not representable in this schema, and the
 * honest queue is the shared one every active courier sees. The first `ambil`
 * wins the parcel; the loser's `ambil` then finds no `siap` row and receives the
 * same 404, and the state machine's lock means the two claims cannot both land.
 * A real assignment would need a migration the reference DDL does not authorise.
 */
final class KurirPesananService
{
    /**
     * The queue's statuses, in the order the queue lists them.
     *
     * @var list<string>
     */
    public const ANTREAN = [PesananObatStatus::Siap->value, PesananObatStatus::SedangDikirim->value];

    /**
     * Every status the detail may be read in: the queue plus the delivered state.
     *
     * @var list<string>
     */
    public const TERLIHAT = [...self::ANTREAN, PesananObatStatus::Selesai->value];

    public function __construct(private readonly PesananObatService $pesanan) {}

    /**
     * The delivery queue: packed orders first, then the in-transit ones, newest
     * activity first inside each group.
     *
     * `FIELD()` is MySQL's own ordering function and this contract is MySQL-only
     * (the reference DDL is the schema), so the group order is one expression
     * rather than two queries unioned together - and `diubah_at` carries
     * `ON UPDATE CURRENT_TIMESTAMP` (`:178`), which makes it the moment the
     * order last MOVED, i.e. the natural "most recent activity" key.
     *
     * @return Collection<int, PesananObat>
     */
    public function antrean(): Collection
    {
        return PesananObat::query()
            ->with('apotek')
            ->whereIn('status', self::ANTREAN)
            ->orderByRaw("FIELD(status, 'siap', 'sedang_dikirim')")
            ->orderByDesc('diubah_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * One order the courier workflow may show, with its full tracking trail.
     *
     * @throws ModelNotFoundException when the order does not exist or is outside
     *                                {@see TERLIHAT} - the same answer for both, deliberately
     */
    public function cari(int $id): PesananObat
    {
        $pesanan = PesananObat::query()
            ->whereKey($id)
            ->whereIn('status', self::TERLIHAT)
            ->with(['apotek', 'pesananObatTracking'])
            ->first();

        if ($pesanan === null) {
            throw (new ModelNotFoundException)->setModel(PesananObat::class, [$id]);
        }

        return $pesanan;
    }

    /**
     * Claim a packed order: `siap -> sedang_dikirim`, optionally stamping the
     * carrier's tracking number on the row the edge is allowed to touch.
     *
     * The `siap` gate is a scoped FIND rather than a re-check of `cari()`'s set:
     * a courier's queue action targets the state it acts on, so an order that is
     * merely visible (in transit under someone else, or already delivered) is a
     * 404 here and the message cannot be read as "this order exists but you
     * cannot have it". The race between this find and the locked transition is
     * closed by the state machine, not by this method: the second claimant's
     * `ubahStatus()` re-reads the row under `lockForUpdate()` and refuses the
     * now-illegal edge with a 422.
     *
     * @param  array{keterangan: ?string, lokasi: ?string, no_resi: ?string}  $data
     *
     * @throws ModelNotFoundException
     */
    public function ambil(int $id, array $data): PesananObat
    {
        $this->temukanUntukAksi($id, PesananObatStatus::Siap->value);

        $pesanan = $this->pesanan->ubahStatus(
            $id,
            PesananObatStatus::SedangDikirim->value,
            $data['keterangan'] ?? 'Pesanan diambil kurir dan sedang dikirim.',
            $data['lokasi'] ?? null,
            $data['no_resi'] ?? null,
        );

        // `load('apotek')` so the write's answer carries the same non-null pickup
        // block the queue and the detail do - `ubahStatus()` returns a refreshed
        // model with no relations, and a resource that answers `apotek: null`
        // after a successful claim would be the same row read twice with two
        // different truths. The tracking trail stays unloaded on purpose: the
        // append happened inside `ubahStatus()` and the client re-reads the
        // detail when it wants the trail.
        return $pesanan->load('apotek');
    }

    /**
     * Confirm delivery: `sedang_dikirim -> selesai`.
     *
     * No `no_resi` here - {@see PesananObatService::ubahStatus()} would refuse
     * it with a 422 of its own for the edge, and the request class forbids the
     * field outright so the refusal arrives as "unknown field" rather than as a
     * workflow message about a parcel that already shipped.
     *
     * @param  array{keterangan: ?string, lokasi: ?string}  $data
     *
     * @throws ModelNotFoundException
     */
    public function antar(int $id, array $data): PesananObat
    {
        $this->temukanUntukAksi($id, PesananObatStatus::SedangDikirim->value);

        return $this->pesanan->ubahStatus(
            $id,
            PesananObatStatus::Selesai->value,
            $data['keterangan'] ?? 'Pesanan telah diterima penerima.',
            $data['lokasi'] ?? null,
        )->load('apotek');
    }

    /**
     * The order in exactly `$status`, or the shared 404.
     *
     * @throws ModelNotFoundException
     */
    private function temukanUntukAksi(int $id, string $status): void
    {
        $ada = PesananObat::query()->whereKey($id)->where('status', $status)->exists();

        if (! $ada) {
            throw (new ModelNotFoundException)->setModel(PesananObat::class, [$id]);
        }
    }
}
