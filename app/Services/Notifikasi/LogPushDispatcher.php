<?php

declare(strict_types=1);

namespace App\Services\Notifikasi;

use Illuminate\Support\Facades\Log;

/**
 * The one push implementation this todo ships, and it writes to the log.
 *
 * ## Why a log line IS the delivery record
 *
 * `notifikasi` has no delivery column. Read the whole table at
 * `telemedicine_test.sql:1036`-`:1048`: `id`, `user_id`, `judul`, `isi`, `tipe`,
 * `tautan`, `payload`, `dibaca_at`, `dibuat_at`. There is no `dikirim_at`, no
 * `status_kirim`, no `channel`, and no `percobaan` - so "queued", "sent" and
 * "failed" are three states a row cannot hold, and `idx_notif (user_id, dibaca_at)`
 * (`:1047`) indexes the READ state rather than the delivery one, which is the
 * schema saying the same thing in a different way.
 *
 * The consequence is a hard constraint rather than a preference: **delivery state
 * must not be invented in the database.** Writing it would mean a migration, a
 * migration is out of scope here, and a migration is exactly the kind of change
 * this project does not make to satisfy a report. So the log is the record, and it
 * is written with a stable event name (`notifikasi.push`) and the whole token
 * context, so an operator can answer "did patient 42's Android phone get the
 * prescription notification" from a log search rather than from a table.
 *
 * ## A SKIPPED device is logged too, and that is the point
 *
 * `user_devices.fcm_token` is `VARCHAR(255) NULL` (`:195`), so a device row with
 * no token is a real state and not a corrupt one; `aktif TINYINT(1) NOT NULL
 * DEFAULT 1` (`:197`) is likewise a real state a logout flips. Skipping both
 * silently is how a "no notifications on my phone" report becomes unanswerable,
 * so every skip is logged with the device id and the reason.
 */
final class LogPushDispatcher implements PushDispatcher
{
    /**
     * {@inheritDoc}
     */
    public function kirim(string $token, array $muatan): void
    {
        Log::info('notifikasi.push', [
            // The notification id, so a log search answers "was THIS row pushed"
            // rather than "was anything pushed around then".
            'notifikasi_id' => $muatan['notifikasi_id'] ?? null,
            'token' => $token,
            'judul' => $muatan['judul'] ?? null,
            'isi' => $muatan['isi'] ?? null,
            'tipe' => $muatan['tipe'] ?? null,
            'tautan' => $muatan['tautan'] ?? null,
            // There is no FCM HTTP client in this project and no credential for
            // one, so nothing is sent over the wire. Saying so IN the log line is
            // what makes the line evidence rather than a claim: an operator
            // reading it learns the push was recorded and not delivered.
            'dikirim' => false,
            'catatan' => 'Belum ada klien FCM; status pengiriman dicatat di log aplikasi.',
        ]);
    }
}
