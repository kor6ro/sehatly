<?php

declare(strict_types=1);

namespace App\Services\Notifikasi;

use App\Enums\NotifikasiTipe;
use App\Models\Notifikasi;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Writes one `notifikasi` row per event and records the push attempt in the log.
 *
 * ## `notifikasi` is an INBOX, and a client may never write to it
 *
 * There is no create endpoint, no update endpoint and no delete endpoint. The two
 * write routes the API exposes are the two read stamps, and they write exactly
 * one column between them - `dibaca_at` - from the application clock. A caller
 * that could set `dibuat_at` could place a notification in its own inbox in the
 * past and make the whole list untrustworthy as a record; a caller that could set
 * `user_id` could read another's. So this service is the only producer, and it is
 * called by the module services, never by a controller.
 *
 * ## SCOPE LIMIT, recorded rather than quietly narrowed
 *
 * This todo wires NO event producer. {@see PdpConsentController} and
 * {@see NotifikasiController} do not create notifications, and neither do
 * `BookingService`, `PaymentService`, `ResepService` or `KonsultasiController`:
 * wiring them would mean editing four other todos' services, and the plan's
 * priority for this task is the consent version rule. What IS delivered is the
 * service, its five event methods, the `tipe` mapping, the deep-link convention
 * and the delivery log - all exercised directly by the test, so the seam a later
 * todo needs is a proved seam rather than an assumption. The wiring is
 * enumerated in `.omo/evidence/task-47-sehatly.md` as the first thing a follow-up
 * should do.
 *
 * ## `tautan` is the API PATH of the record, and `payload` carries the identifier
 *
 * `notifikasi` has both a `tautan VARCHAR(500) NULL` (`:1042`) and a `payload JSON
 * NULL` (`:1043`), and they are not redundant here. `tautan` is what a client
 * navigates to, so it is a path a router can consume. `payload` is what it reads
 * once there, so it is a keyed object with a number in it and not a string to
 * re-parse. The plan's wording is "`payload` JSON carrying the deep link"; this
 * class puts the identifier in `payload` and the path in `tautan` and asserts BOTH
 * in the test, which is the only arrangement in which a client never has to
 * substring-parse a path to get an id out.
 *
 * ## The seven events, and why these seven
 *
 * | method | `tipe` | `tautan` | producer that should call it |
 * | --- | --- | --- | --- |
 * | `bookingDibuat` | `booking` | `/api/v1/booking/{id}` | `BookingService` (todo 27) |
 * | `bookingDibatalkan` | `booking` | `/api/v1/booking/{id}` | `BookingService` (todo 27) |
 * | `bookingJadwalUlang` | `booking` | `/api/v1/booking/{id}` | `BookingService` (F12) |
 * | `pembayaranSelesai` | `pembayaran` | `/api/v1/invoice/{id}` | `PaymentService` (todo 45) |
 * | `resepSiap` | `resep` | `/api/v1/resep/{id}` | `ResepService` (todo 40) |
 * | `pesanBaru` | `chat` | `/api/v1/konsultasi/{id}/chat` | `KonsultasiController` (todo 26) |
 * | `ulasanDiminta` | `sistem` | `/api/v1/konsultasi/{id}/ulasan` | `KonsultasiService::selesai()` (F04) |
 *
 * `notifikasi.tipe` is a SEVEN-value ENUM (`:1041`) and two of the seven - `lab`
 * and `promo` - have no producer in this application. They are left alone rather
 * than filled with an invented event, and {@see NotifikasiTipe::nilaiYangDipakai()}
 * is the checked spelling of the five this class writes. `ulasanDiminta` is the
 * first `sistem` producer: there is no `ulasan` member in the ENUM, the review
 * invitation carries no clinical content, and `sistem` is the schema's own word
 * for a service notice (the same value `konsultasi_chat.pengirim_tipe` uses for its
 * lifecycle lines).
 *
 * ## The row is saved through the MODEL, deliberately
 *
 * `Notifikasi` hangs off `users` (`:1046`) and is therefore in `AuditScope`'s
 * person closure, so the global `AuditObserver` audits it. A
 * `DB::table('notifikasi')->insert()` fires no Eloquent event, so this service
 * would be the one writer in the table that leaves no `audit_log` row. The test
 * asserts five events produce five `create` rows for exactly that reason.
 */
final class NotificationService
{
    public function __construct(
        private readonly PushDispatcher $push,
    ) {}

    /**
     * A booking was created. `tipe = 'booking'`.
     */
    public function bookingDibuat(User $user, int $bookingId): Notifikasi
    {
        return $this->kirim($user, NotifikasiTipe::Booking, 'Booking berhasil dibuat.', 'Pesanan Konsultasi Anda sudah tercatat.', '/api/v1/booking/'.$bookingId, ['booking_id' => $bookingId]);
    }

    /**
     * A booking was cancelled, and WHY. `tipe = 'booking'`.
     *
     * `booking.dibatalkan_oleh` is taken from `users.tipe` and a cancellation is
     * something a person is usually unhappy about, so the reason travels in the
     * payload rather than only in the prose: the client shows `isi` and links
     * `alasan` to the screen that explains it.
     */
    public function bookingDibatalkan(User $user, int $bookingId, string $alasan): Notifikasi
    {
        return $this->kirim($user, NotifikasiTipe::Booking, 'Booking dibatalkan.', 'Booking Anda dibatalkan: '.$alasan, '/api/v1/booking/'.$bookingId, ['booking_id' => $bookingId, 'alasan' => $alasan]);
    }

    /**
     * A booking was moved to a new schedule. `tipe = 'booking'`.
     *
     * ## The body is GENERIC, deliberately
     *
     * A reschedule is F12's new notification trigger, and its recipients are
     * the other party - which can be a DOCTOR. The push `isi` is the least
     * private field this application has: it renders on a lock screen, in a
     * notification tray, and on any device the account is signed into. So
     * `isi` names no date, no time and no clinical detail beyond "your booking
     * moved"; the new schedule travels in `payload`, which is read only after
     * the recipient opens the app and is scoped to their own inbox row. The
     * cancellation-reason free text that `bookingDibatalkan()` carries in its
     * `isi` is a known privacy gap (F11/F12) and this method must not copy
     * the pattern.
     *
     * The date and time in `payload` are the Asia/Jakarta wall-clock strings
     * the booking row stores, unchanged: they are exactly what the in-app
     * screen renders, and converting them here would be the seven-hour
     * timezone defect `docs/timezone-policy.md` exists to prevent.
     */
    public function bookingJadwalUlang(User $user, int $bookingId, string $tanggal, string $slotMulai): Notifikasi
    {
        return $this->kirim(
            $user,
            NotifikasiTipe::Booking,
            'Jadwal booking diubah.',
            'Jadwal booking Anda telah dipindahkan.',
            '/api/v1/booking/'.$bookingId,
            [
                'booking_id' => $bookingId,
                'tanggal_kunjungan' => $tanggal,
                'slot_mulai' => $slotMulai,
            ],
        );
    }

    /**
     * An invoice was settled. `tipe = 'pembayaran'`.
     */
    public function pembayaranSelesai(User $user, int $invoiceId): Notifikasi
    {
        return $this->kirim($user, NotifikasiTipe::Pembayaran, 'Pembayaran berhasil.', 'Pembayaran Anda telah diterima.', '/api/v1/invoice/'.$invoiceId, ['invoice_id' => $invoiceId]);
    }

    /**
     * A prescription is ready for the pharmacy or for pickup. `tipe = 'resep'`.
     */
    public function resepSiap(User $user, int $resepId): Notifikasi
    {
        return $this->kirim($user, NotifikasiTipe::Resep, 'Resep siap.', 'Resep Anda sudah diverifikasi dan siap.', '/api/v1/resep/'.$resepId, ['resep_id' => $resepId]);
    }

    /**
     * A new message arrived in a consultation. `tipe = 'chat'`.
     *
     * The sender is in the payload as a `users.id` rather than as a name: a
     * notification row is a durable record, and a name is neither unique nor
     * stable, while `users.id` is the primary key `konsultasi_chat.pengirim_user_id`
     * already points at.
     */
    public function pesanBaru(User $user, int $konsultasiId, int $pengirimUserId): Notifikasi
    {
        return $this->kirim($user, NotifikasiTipe::Chat, 'Pesan baru.', 'Ada pesan baru di konsultasi Anda.', '/api/v1/konsultasi/'.$konsultasiId.'/chat', [
            'konsultasi_id' => $konsultasiId,
            'pengirim_user_id' => $pengirimUserId,
        ]);
    }

    /**
     * A consultation finished and the patient may now review it. `tipe = 'sistem'`.
     *
     * ## The body is GENERIC, and that is a hard rule (F11)
     *
     * A push `isi` renders on a lock screen and in a notification tray, so it must
     * not carry clinical content: no diagnosis, no SOAP note, no prescription and
     * no consultation summary. This method names none of them - the message is
     * the same sentence for every consultation, and the only identifier it
     * carries travels in `payload`, which is read after the recipient opens the
     * app and is scoped to their own inbox row. The deep link points at the
     * review write surface; it never carries the review body or a rating.
     *
     * This is the `sistem` producer. `notifikasi.tipe` has no `ulasan` member, and
     * a service reminder about the caller's own completed session is exactly what
     * `sistem` declares (the same value `konsultasi_chat.pengirim_tipe` uses).
     */
    public function ulasanDiminta(User $user, int $konsultasiId): Notifikasi
    {
        return $this->kirim(
            $user,
            NotifikasiTipe::Sistem,
            'Konsultasi selesai.',
            'Anda dapat menulis ulasan untuk dokter ini.',
            '/api/v1/konsultasi/'.$konsultasiId.'/ulasan',
            ['konsultasi_id' => $konsultasiId],
        );
    }

    /**
     * Write the row, then attempt one push per ACTIVE device that has a token.
     *
     * @param  array<string, mixed>  $payload
     */
    private function kirim(User $user, NotifikasiTipe $tipe, string $judul, string $isi, string $tautan, array $payload): Notifikasi
    {
        $baris = $this->catat($user, $tipe, $judul, $isi, $tautan, $payload);

        $this->dorong($user, $baris);

        return $baris;
    }

    /**
     * Write the in-app `notifikasi` row and NOTHING else - no push.
     *
     * ## Why the write and the push are separable (F11)
     *
     * In-app delivery is unconditional: F11's rule is that push is not the
     * source of truth, so the row is always written and only the push can be
     * suppressed. A reminder dispatch is the one producer that has to decide
     * between the two after the row exists - consent, the per-type switch and
     * quiet hours are all checked against the user's own settings - so it calls
     * this method, then {@see dorong()} only when every gate allows it. The
     * event methods above keep the combined `kirim()` behaviour byte for byte.
     *
     * The row is saved through the MODEL, deliberately - see the class
     * docblock: `Notifikasi` is in `AuditScope`'s person closure and the global
     * observer audits it, so a query-builder insert here would be the one write
     * in the table that leaves no `audit_log` row.
     *
     * @param  array<string, mixed>  $payload
     */
    public function catat(User $user, NotifikasiTipe $tipe, string $judul, string $isi, ?string $tautan, array $payload): Notifikasi
    {
        $baris = new Notifikasi;
        $baris->user_id = (int) $user->getKey();
        $baris->judul = $this->potong($judul, 200);
        $baris->isi = $this->potong($isi, 500);
        $baris->tipe = $tipe->value;
        $baris->tautan = $this->potong((string) $tautan, 500);
        $baris->payload = $payload;
        $baris->dibaca_at = null;
        $baris->save();

        return $baris;
    }

    /**
     * One push attempt per active device, and a LOG line for every skip.
     *
     * Public since F11 so a caller that wrote the row through {@see catat()}
     * can decide separately whether to push it. The device rows are read with
     * the query builder rather than through a `UserDevice` relation, because
     * this is a read of two columns and there is no relation on the model to
     * hang a constraint off.
     */
    public function dorong(User $user, Notifikasi $baris): void
    {
        $perangkat = DB::table('user_devices')
            ->where('user_id', (int) $user->getKey())
            ->get(['id', 'device_id', 'platform', 'fcm_token', 'aktif']);

        $muatan = [
            'judul' => $baris->judul,
            'isi' => $baris->isi,
            'tipe' => $baris->tipe,
            'tautan' => $baris->tautan,
        ];

        foreach ($perangkat as $satu) {
            if ((int) $satu->aktif !== 1) {
                $this->lewati($satu->id, 'perangkat tidak aktif');

                continue;
            }

            $token = $satu->fcm_token;

            if ($token === null || $token === '') {
                $this->lewati($satu->id, 'tidak ada fcm_token');

                continue;
            }

            $this->push->kirim($token, array_merge($muatan, ['notifikasi_id' => (int) $baris->getKey()]));

            Log::info('notifikasi.push.terkirim', [
                'notifikasi_id' => (int) $baris->getKey(),
                'user_id' => (int) $user->getKey(),
                'perangkat_id' => (int) $satu->id,
                'platform' => (string) $satu->platform,
            ]);
        }
    }

    /**
     * A device that was not pushed to, and why.
     *
     * A skip with no line written is indistinguishable from a push that was never
     * attempted, which is the failure this project has already paid for once on
     * a different surface.
     */
    private function lewati(int $perangkatId, string $alasan): void
    {
        Log::info('notifikasi.push.lewat', [
            'perangkat_id' => $perangkatId,
            'alasan' => $alasan,
        ]);
    }

    /**
     * Cut a string to the width its column declares, rather than letting MySQL
     * truncate in strict mode and raise.
     *
     * `judul VARCHAR(200)` (`:1039`), `isi VARCHAR(500)` (`:1040`) and
     * `tautan VARCHAR(500)` (`:1042`). The inputs are this class's own literals
     * except `isi`, which embeds a caller-supplied `alasan` - and a free-text
     * cancellation reason is exactly the field that eventually exceeds 500.
     */
    private function potong(string $teks, int $panjang): string
    {
        return mb_substr($teks, 0, $panjang);
    }
}
