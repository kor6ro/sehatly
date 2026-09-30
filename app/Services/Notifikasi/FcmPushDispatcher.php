<?php

declare(strict_types=1);

namespace App\Services\Notifikasi;

use App\Support\Push\KredensialFirebase;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * F-005: FCM HTTP v1, behind the {@see PushDispatcher} seam the project already had.
 *
 * ## It never throws, and that is the contract with the caller
 *
 * `NotificationService::dorong()` calls this once per active device from inside the
 * service that raised the notification - which is inside a booking, a payment
 * settlement or a prescription verification. A push that threw would turn a
 * delivered booking into a 500. So every failure path here logs and returns:
 * a missing credential, a refused token exchange, a network fault, a provider 5xx.
 *
 * ## An UNREGISTERED token is retired, not retried
 *
 * FCM answers `404 NOT_FOUND` / `400 INVALID_ARGUMENT` with an `UNREGISTERED` error
 * code when a device token is dead - the app was uninstalled, the token rotated,
 * the install wiped. Retrying it forever is a permanent failure that looks like a
 * transient one, so the device row is flipped to `aktif = 0` and the skip is
 * logged. `aktif` is the same flag a logout sets, so the state is one the rest of
 * the application already understands.
 *
 * ## The log never carries the notification body
 *
 * `notifikasi.isi` is clinical-adjacent text. `LogPushDispatcher` writes it because
 * the log IS the delivery there; a real transport must not copy it into a second
 * place, so the lines here carry the notification id, the device-facing status and
 * the exception class, and nothing else.
 */
final class FcmPushDispatcher implements PushDispatcher
{
    /**
     * {@inheritDoc}
     */
    public function kirim(string $token, array $muatan): void
    {
        $notifikasiId = $muatan['notifikasi_id'] ?? null;

        try {
            $kredensial = KredensialFirebase::muat();

            $response = Http::withToken($kredensial->tokenAkses())
                ->timeout((int) config('push.firebase.timeout'))
                ->post($kredensial->endpoint().'/projects/'.$kredensial->projectId().'/messages:send', [
                    'message' => [
                        'token' => $token,
                        'notification' => [
                            'title' => (string) ($muatan['judul'] ?? ''),
                            'body' => (string) ($muatan['isi'] ?? ''),
                        ],
                        'data' => $this->data($muatan),
                    ],
                ]);
        } catch (Throwable $e) {
            // A misconfigured deployment or a transport fault. Logged, never thrown:
            // the write that raised this notification has already succeeded.
            Log::error('notifikasi.push.fcm_gagal', [
                'notifikasi_id' => $notifikasiId,
                'sebab' => $e::class,
            ]);

            return;
        }

        if ($response->successful()) {
            return;
        }

        if ($this->tokenMati($response)) {
            DB::table('user_devices')
                ->where('fcm_token', $token)
                ->update(['aktif' => 0]);

            Log::warning('notifikasi.push.fcm_token_mati', [
                'notifikasi_id' => $notifikasiId,
                'status' => $response->status(),
            ]);

            return;
        }

        Log::warning('notifikasi.push.fcm_gagal', [
            'notifikasi_id' => $notifikasiId,
            'status' => $response->status(),
        ]);
    }

    /**
     * The FCM `data` block is a map of STRINGS - a nested value or an integer is a
     * 400 - so the three fields a client routes on are stringified and the empty
     * ones are dropped rather than sent as `""`.
     *
     * @param  array<string, mixed>  $muatan
     * @return array<string, string>
     */
    private function data(array $muatan): array
    {
        $data = [];

        foreach (['tipe', 'tautan', 'notifikasi_id'] as $key) {
            $value = $muatan[$key] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            $data[$key] = (string) $value;
        }

        return $data;
    }

    /**
     * Is this refusal "the token is dead", as opposed to a transient fault?
     *
     * FCM v1 puts the machine-readable cause in `error.details[].errorCode`; the
     * coarse `error.status` is the fallback. Both spellings are checked because the
     * shape differs between a bad token and a bad request.
     */
    private function tokenMati(Response $response): bool
    {
        if (! in_array($response->status(), [400, 404], true)) {
            return false;
        }

        $kode = (string) ($response->json('error.details.0.errorCode')
            ?? $response->json('error.status')
            ?? '');

        return in_array($kode, ['UNREGISTERED', 'NOT_FOUND', 'INVALID_ARGUMENT'], true);
    }
}
