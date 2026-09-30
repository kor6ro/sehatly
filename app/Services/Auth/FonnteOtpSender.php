<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Support\KontakMasker;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * F-005: the Fonnte (WhatsApp) OTP sender, for the prototype only.
 *
 * ## Fonnte is an UNOFFICIAL channel
 *
 * Fonnte drives WhatsApp Web on the operator's own number. It has no SLA, no
 * delivery guarantee, and a real risk of the number being blocked by WhatsApp.
 * `docs/otp-push-prototype.md` says so in full, and nothing here should be read as
 * production guidance. Before real patient data the channel must move to WhatsApp
 * Cloud API / an official BSP, or to a contracted SMS gateway - which is a
 * container binding change, not a rewrite, because this class sits behind
 * {@see OtpSender}.
 *
 * ## Delivery failure is LOGGED, never thrown
 *
 * {@see OtpSender} requires it: "Implementations must not throw for a delivery
 * failure the caller can still recover from; they should log it instead." An OTP
 * that was minted but not delivered stays a live `user_otp` row, and the account
 * holder can request a fresh code, which supersedes it. A timeout, a 4xx or a 5xx
 * therefore produces one warning line and a return - never a 500 on a registration
 * that actually succeeded.
 *
 * ## The code never reaches the log
 *
 * The warning carries the purpose, the masked destination and the failure shape -
 * never `$kode`, and never the provider's raw response body, which can echo the
 * submitted payload (code included). `FonnteOtpSenderTest` asserts the absence by
 * searching the whole log context for the code.
 */
final class FonnteOtpSender implements OtpSender
{
    public function send(string $tujuan, string $kode, string $purpose): void
    {
        $token = (string) config('otp.fonnte.token');

        if ($token === '') {
            // `PenjagaPengirimanProduksi` refuses to boot a non-local `fonnte`
            // deployment with no token, so this is reachable only in local/testing.
            Log::warning('otp.fonnte.tanpa_token', ['tujuan' => $purpose]);

            return;
        }

        try {
            $response = Http::withHeaders(['Authorization' => $token])
                ->timeout((int) config('otp.fonnte.timeout'))
                ->asForm()
                ->post((string) config('otp.fonnte.url'), [
                    'target' => $tujuan,
                    'message' => 'Kode OTP Sehatly: '.$kode
                        .'. Berlaku '.OtpService::TTL_MENIT.' menit. Jangan bagikan kode ini kepada siapa pun.',
                ]);
        } catch (ConnectionException|Throwable $e) {
            Log::warning('otp.fonnte.gagal', [
                'tujuan' => $purpose,
                'penerima' => KontakMasker::mask($tujuan),
                'sebab' => $e::class,
            ]);

            return;
        }

        if (! $response->successful()) {
            // The STATUS only. The body can echo the submitted `message`, and the
            // submitted message contains the code.
            Log::warning('otp.fonnte.gagal', [
                'tujuan' => $purpose,
                'penerima' => KontakMasker::mask($tujuan),
                'status' => $response->status(),
            ]);
        }
    }
}
