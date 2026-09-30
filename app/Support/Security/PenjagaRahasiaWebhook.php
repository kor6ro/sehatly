<?php

declare(strict_types=1);

namespace App\Support\Security;

use Illuminate\Contracts\Foundation\Application;

/**
 * F-006: refuse to boot outside local/testing while a webhook secret is the shipped
 * default.
 *
 * ## Why boot, and not the first webhook request
 *
 * `POST /api/v1/webhook/payment/{gateway}` is unauthenticated: a payment provider
 * holds no Sanctum token, and the only thing standing between the internet and
 * "mark any invoice paid" is the HMAC. `config/services.php` ships a literal
 * `UBAH-SEKRET-WEBHOOK-*` default per gateway so local development needs no
 * `.env` edit - and those literals are in the repository. A deployment that forgot
 * to override one would accept a forged delivery and look entirely healthy: the
 * signature VERIFIES, so nothing fails, and no log line says "your key is public".
 * A boot that refuses is the only failure mode loud enough to catch it.
 *
 * ## Why `local` and `testing` are exempt
 *
 * The default is legitimate in exactly the two environments that use it, and the
 * test suite runs in `testing` (`phpunit.xml`). Exempting `testing` is not a hole:
 * `tests/Unit/Security/PenjagaRahasiaWebhookTest.php` sets `APP_ENV=production`
 * directly and boots a real subprocess to prove the refusal fires.
 *
 * ## What counts as "still the default"
 *
 * The prefix, not the literal. All four shipped values share
 * {@see AWALAN_BAWAAN}, and a prefix test also catches a partially-edited value
 * that kept the marker. An EMPTY secret is refused for the same reason a default
 * is: an HMAC keyed on the empty string is a public key. Nothing here reads the
 * secret out; only its first bytes and its emptiness are examined.
 */
final class PenjagaRahasiaWebhook
{
    /** The prefix every shipped default in `config/services.php` carries. */
    public const AWALAN_BAWAAN = 'UBAH-SEKRET-WEBHOOK-';

    /**
     * The environments where the shipped default is the intended value.
     *
     * @var list<string>
     */
    public const LINGKUNGAN_DIKECUALIKAN = ['local', 'testing'];

    /**
     * @throws RahasiaWebhookDefaultException when a gateway still carries the default
     */
    public static function pastikan(Application $app): void
    {
        if ($app->environment(self::LINGKUNGAN_DIKECUALIKAN)) {
            return;
        }

        $tertinggal = [];

        foreach ((array) config('services.payment.gateways', []) as $nama => $gateway) {
            $rahasia = is_array($gateway) ? (string) ($gateway['webhook_secret'] ?? '') : '';

            if ($rahasia === '' || str_starts_with($rahasia, self::AWALAN_BAWAAN)) {
                $tertinggal[] = (string) $nama;
            }
        }

        if ($tertinggal !== []) {
            throw RahasiaWebhookDefaultException::untukGateway($tertinggal);
        }
    }
}
