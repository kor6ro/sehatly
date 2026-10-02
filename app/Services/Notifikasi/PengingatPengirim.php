<?php

declare(strict_types=1);

namespace App\Services\Notifikasi;

use App\Enums\NotifikasiTipe;
use App\Enums\PengingatJenis;
use App\Enums\PengingatStatus;
use App\Enums\PersetujuanPdpJenis;
use App\Enums\ZonaWaktu;
use App\Models\Notifikasi;
use App\Models\Pengingat;
use App\Models\PengingatTerkirim;
use App\Models\User;
use App\Services\Pdp\PdpConsent;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The F11 scheduler: evaluate every active reminder and dispatch the ones due
 * NOW, once per `(pengingat, tanggal, waktu)`.
 *
 * ## The rules, in the order they are applied
 *
 * 1. **Window.** `tanggal_mulai <= today <= tanggal_mulai + lama_hari - 1`, all
 *    on the reminder's own `zona_waktu`; a `NULL` `lama_hari` is open-ended.
 * 2. **Time.** The reminder's local `HH:MM` must literally equal one of its
 *    `waktu` entries. Partial minutes are deliberately NOT matched: a `09:00`
 *    dose that a 09:01 tick arrives late for is a missed dose, not a 09:01 one.
 * 3. **Idempotency.** `pengingat_terkirim` is inserted FIRST. A second run for
 *    the same `(pengingat, tanggal, waktu)` finds the unique row and returns
 *    without writing a second notification. The insert happens before the row
 *    it protects, so a crash between them loses one dispatch rather than
 *    double-sending one - the failure direction this table exists to choose.
 * 4. **In-app row: ALWAYS.** `NotificationService::catat()` writes a `sistem`
 *    (or `booking`, for an appointment reminder) row for the owner, whether or
 *    not any push goes out. Push is not the source of truth.
 * 5. **Push: only when ALL of** the user's latest
 *    `komunikasi_tindak_lanjut`/`pemasaran` consent is an approval
 *    (`persetujuan_pdp` is an append-only ledger - {@see PdpConsent::disetujui()}
 *    reads the latest row per jenis), the per-type `push_aktif` switch allows
 *    it, and the instant is outside quiet hours. The three failures are
 *    independent: a missing consent, a switched-off type and a quiet window
 *    each suppress push on their own.
 *
 * ## The push body is GENERIC, deliberately
 *
 * The push `data` is built from the row's `judul`/`isi`, and that body is what
 * renders on a lock screen. It therefore names no drug, no dose and no
 * appointment detail - the same "generic by construction" rule
 * `NotificationService::bookingJadwalUlang()` documents. The reminder's real
 * content lives on `pengingat` and is read after the user opens the app.
 *
 * ## Why it walks every active reminder
 *
 * Time zones make a set-based "due now" query wrong: `09:00` is a different
 * instant in WIB, WITA and WIT, and `tanggal_mulai + lama_hari` differs on the
 * same day for the same reason. The candidate set is one user's reminders -
 * small, indexed by `(user_id, status)` - so the walk is chunked by primary key
 * and each row is evaluated on its own clock rather than approximated in SQL.
 */
final class PengingatPengirim
{
    /**
     * Reminders evaluated per chunk. Matches the chunked-write convention
     * `NotifikasiController::BACA_SEMUA_PER_BATCH` established.
     */
    public const PER_BATCH = 200;

    public function __construct(
        private readonly NotificationService $notifikasi,
        private readonly PreferensiNotifikasiService $preferensi,
        private readonly PdpConsent $pdp,
    ) {}

    /**
     * Evaluate every active reminder against `$sekarang` (defaults to now).
     *
     * @return array{diperiksa: int, dikirim: int, duplikat: int, push_ditahan: int}
     */
    public function kirim(?CarbonInterface $sekarang = null): array
    {
        $sekarang ??= CarbonImmutable::now();

        $hasil = ['diperiksa' => 0, 'dikirim' => 0, 'duplikat' => 0, 'push_ditahan' => 0];

        Pengingat::query()
            ->where('status', PengingatStatus::Aktif->value)
            ->orderBy('id')
            ->chunkById(self::PER_BATCH, function ($rows) use ($sekarang, &$hasil): void {
                foreach ($rows as $pengingat) {
                    $hasil['diperiksa']++;

                    $dikirim = $this->proses($pengingat, $sekarang);

                    if ($dikirim === null) {
                        continue;
                    }

                    if ($dikirim) {
                        $hasil['dikirim']++;
                    } else {
                        $hasil['duplikat']++;
                    }
                }
            });

        return $hasil;
    }

    /**
     * `true` = dispatched, `false` = already dispatched for this key, `null` =
     * not due (wrong day or wrong minute).
     *
     * Public so a single reminder can be driven directly by a test without a
     * full-table walk; the command uses {@see kirim()}.
     */
    public function proses(Pengingat $pengingat, CarbonInterface $sekarang): ?bool
    {
        $zona = $pengingat->zona_waktu !== null && $pengingat->zona_waktu !== ''
            ? $pengingat->zona_waktu
            : ZonaWaktu::DEFAULT;

        $lokal = $sekarang->setTimezone($zona);
        $tanggal = $lokal->format('Y-m-d');

        if (! $this->dalamJendela($pengingat, $tanggal)) {
            return null;
        }

        $jam = $lokal->format('H:i');

        if (! in_array($jam, $pengingat->waktu ?? [], true)) {
            return null;
        }

        $user = $pengingat->user;

        if ($user === null) {
            // The FK cascades, so this is unreachable through the database; it
            // covers an in-memory row a test built without saving it.
            return null;
        }

        /** @var array{0: PengingatTerkirim, 1: Notifikasi}|null $hasil */
        $hasil = DB::transaction(function () use ($pengingat, $tanggal, $jam, $user): ?array {
            // `firstOrCreate` is mass assignment, and NO model in this project
            // declares `$fillable` (the framework's `$guarded = ['*']` default
            // is the project's standing rule, asserted by `ArchitectureTest`),
            // so the guard is lifted for this one framework call rather than by
            // adding a fillable list to the model. The insert still goes through
            // the MODEL, so the global `AuditObserver` records it.
            $penanda = PengingatTerkirim::unguarded(fn (): PengingatTerkirim => PengingatTerkirim::query()->firstOrCreate(
                [
                    'pengingat_id' => (int) $pengingat->getKey(),
                    'tanggal' => $tanggal,
                    'waktu' => $jam.':00',
                ],
            ));

            if (! $penanda->wasRecentlyCreated) {
                return null;
            }

            $notifikasi = $this->notifikasi->catat(
                $user,
                $this->tipeNotifikasi($pengingat),
                $this->judul($pengingat),
                $this->isi($pengingat),
                $this->tautan($pengingat),
                $this->payload($pengingat),
            );

            $penanda->notifikasi_id = (int) $notifikasi->getKey();
            $penanda->save();

            return [$penanda, $notifikasi];
        });

        if ($hasil === null) {
            return false;
        }

        if ($this->bolehPush($user, $pengingat, $sekarang)) {
            $this->notifikasi->dorong($user, $hasil[1]);
        }

        return true;
    }

    /**
     * The in-app `notifikasi.tipe` a reminder maps onto.
     *
     * `janji_temu` IS a booking event and uses `booking` - which also gives it a
     * row in the per-type push matrix. `obat` has no produced type and uses the
     * schema's catch-all `sistem`; no new ENUM value is invented.
     */
    public function tipeNotifikasi(Pengingat $pengingat): NotifikasiTipe
    {
        return $pengingat->jenis === PengingatJenis::JanjiTemu->value
            ? NotifikasiTipe::Booking
            : NotifikasiTipe::Sistem;
    }

    /**
     * Consent AND the per-type switch AND quiet hours.
     */
    private function bolehPush(User $user, Pengingat $pengingat, CarbonInterface $sekarang): bool
    {
        $tindakLanjut = $this->pdp->disetujui($user, PersetujuanPdpJenis::KomunikasiTindakLanjut->value);
        $pemasaran = $this->pdp->disetujui($user, PersetujuanPdpJenis::Pemasaran->value);

        if (! $tindakLanjut && ! $pemasaran) {
            return false;
        }

        if (! $this->preferensi->pushAktif($user, $this->tipeNotifikasi($pengingat)->value)) {
            return false;
        }

        return ! $this->preferensi->dalamJamTenang($user, $sekarang);
    }

    /**
     * Local-date window: `tanggal_mulai <= $tanggal <= akhir`, where `akhir`
     * exists only when `lama_hari` is set.
     */
    private function dalamJendela(Pengingat $pengingat, string $tanggal): bool
    {
        $mulai = $pengingat->tanggal_mulai?->format('Y-m-d');

        if ($mulai === null || $mulai > $tanggal) {
            return false;
        }

        if ($pengingat->lama_hari === null) {
            return true;
        }

        $akhir = $pengingat->tanggal_mulai->copy()->addDays(((int) $pengingat->lama_hari) - 1)->format('Y-m-d');

        return $tanggal <= $akhir;
    }

    private function judul(Pengingat $pengingat): string
    {
        return $pengingat->jenis === PengingatJenis::JanjiTemu->value
            ? 'Pengingat janji temu.'
            : 'Pengingat minum obat.';
    }

    private function isi(Pengingat $pengingat): string
    {
        return $pengingat->jenis === PengingatJenis::JanjiTemu->value
            ? 'Anda punya janji temu yang sudah dekat.'
            : 'Waktunya minum obat sesuai jadwal Anda.';
    }

    private function tautan(Pengingat $pengingat): ?string
    {
        if ($pengingat->jenis === PengingatJenis::JanjiTemu->value && $pengingat->booking_id !== null) {
            return '/api/v1/booking/'.$pengingat->booking_id;
        }

        // A drug reminder has no SPA page path in the F11 deep-link whitelist;
        // null keeps the row non-clickable rather than inventing a route.
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Pengingat $pengingat): array
    {
        $payload = ['pengingat_id' => (int) $pengingat->getKey()];

        if ($pengingat->booking_id !== null) {
            $payload['booking_id'] = (int) $pengingat->booking_id;
        }

        if ($pengingat->obat_id !== null) {
            $payload['obat_id'] = (int) $pengingat->obat_id;
        }

        return $payload;
    }
}
