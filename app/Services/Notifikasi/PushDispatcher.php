<?php

declare(strict_types=1);

namespace App\Services\Notifikasi;

/**
 * The contract for a push, and nothing else.
 *
 * ## Why an interface for a logger
 *
 * `notifikasi` cannot record a delivery: it has `dibaca_at` (`:1044`) and no
 * delivery-state column, no channel column, and no attempt counter - the absence
 * of all three is asserted from the DDL by this todo's test. So a push either
 * happened, failed, or never left, and the only place that can be written down is
 * the application log. That makes the "transport" a genuine seam: the log is the
 * one implementation this todo ships, and a real FCM client would be another,
 * with the same call site.
 *
 * One method rather than a `kirim()`/`jadwal()`/`batal()` triple, because the
 * schema supports exactly one fact - a token was or was not used - and a contract
 * with three verbs would invite an implementation to invent the other two.
 */
interface PushDispatcher
{
    /**
     * Deliver one push to one device token.
     *
     * @param  array<string, mixed>  $muatan  the notification's `judul`, `isi`, `tipe` and `tautan`
     */
    public function kirim(string $token, array $muatan): void;
}
