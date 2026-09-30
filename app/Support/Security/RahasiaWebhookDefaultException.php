<?php

declare(strict_types=1);

namespace App\Support\Security;

use RuntimeException;

/**
 * F-006: a non-local boot with a webhook secret that is still the shipped default.
 *
 * `config/services.php` ships a literal `UBAH-SEKRET-WEBHOOK-*` default for each of
 * the four gateways, so the application runs locally without a `.env` edit. Those
 * literals are IN THE REPOSITORY, and on a deployment that did not override them the
 * HMAC key standing between the internet and "mark any invoice paid" is public. The
 * failure is otherwise silent - a forged delivery simply verifies - which is why it
 * is refused at boot rather than logged.
 *
 * The message names the gateway and its environment variable. It never echoes a
 * secret: in the unsafe case the secret is a published constant, and in the safe
 * case this class is never constructed.
 */
final class RahasiaWebhookDefaultException extends RuntimeException
{
    /**
     * @param  list<string>  $gateway
     */
    public static function untukGateway(array $gateway): self
    {
        $variabel = array_map(
            static fn (string $nama): string => 'PAYMENT_WEBHOOK_SECRET_'.strtoupper($nama),
            $gateway,
        );

        return new self(
            'Refusing to boot: the payment webhook secret for ['.implode(', ', $gateway).'] is still the '
            .'shipped default, which is committed to this repository, so anyone holding the repository can '
            .'forge a delivery that marks an invoice paid. Set '.implode(', ', $variabel).' in the '
            .'environment. APP_ENV is not local or testing, so the default is no longer acceptable.',
        );
    }
}
