<?php

declare(strict_types=1);

namespace App\Services\Payment;

use Illuminate\Auth\AuthenticationException;

/**
 * The signature on a provider notification did not verify.
 *
 * ## Why a dedicated class and not a `ValidationException`
 *
 * A 422 and a 401 are different claims. A 422 says "I understood your request
 * and the DATA in it is wrong"; a 401 says "I will not act on this because I
 * cannot establish that you are who you say you are". A forged signature is the
 * second, and answering it with a 422 would tell a prober that the endpoint
 * exists, that it reads the body, and that only the header was wrong - which is
 * exactly the information an attacker iterating on a forgery wants.
 *
 * So it extends `AuthenticationException`, which `bootstrap/app.php` already
 * maps to `401 {"success":false,"message":"Unauthenticated.","errors":{}}` for
 * every `api/*` request. Reusing the framework's own exception rather than
 * adding a render branch to the kernel means this todo adds no fifth place
 * where an error shape is decided.
 *
 * ## The message is the framework's, on purpose
 *
 * `AuthenticationException` renders with a fixed string rather than
 * `$this->getMessage()`. That is the right default here: the specific reason -
 * absent header, wrong length, wrong key - is useful to the operator reading
 * the log and useless to the caller, who learns only that the request was not
 * authenticated. {@see reportable()} is where the distinction is written down.
 *
 * ## It is thrown, never returned
 *
 * A `verifyWebhook()` that returned `null` on a bad signature would let a
 * caller write `$result ?? $this->settle()` by accident, and the one line that
 * must never run would be the one that runs. Throwing makes the failure
 * loud: a caller that forgets the `try` gets a 500, which is a safe direction.
 */
final class TandaTanganWebhookTidakValid extends AuthenticationException
{
    /**
     * Why the signature did not verify, for the LOG and for nothing else.
     *
     * The caller never sees this: `bootstrap/app.php` renders every
     * `AuthenticationException` with the fixed string `Unauthenticated.`. This
     * value reaches `report()` and the log, where the difference between "no
     * signature header at all" and "a signature that does not match" is the
     * difference between a misconfigured provider and a forgery attempt.
     */
    private readonly string $alasan;

    private function __construct(string $alasan)
    {
        parent::__construct();

        $this->alasan = $alasan;
    }

    /**
     * The header never arrived, or arrived empty.
     *
     * The empty case is its own factory rather than folded into the mismatch
     * one because `hash_equals('', $expected)` is already `false` and the two
     * deserve different log lines.
     */
    public static function tidakAda(): self
    {
        return new self('Header tanda tangan webhook tidak dikirim.');
    }

    /**
     * A signature was sent and it is not this delivery's signature.
     */
    public static function tidakCocok(): self
    {
        return new self('Tanda tangan webhook tidak cocok dengan badan permintaan.');
    }

    /**
     * The gateway named no configured secret.
     *
     * A deployment fault rather than an attack, and the reason it is a 401
     * rather than a 500 is that a 500 would invite a provider to keep retrying
     * a request that can never succeed.
     */
    public static function rahasiaTidakAda(string $gateway): self
    {
        return new self('Gateway ['.$gateway.'] tidak punya secret webhook yang dikonfigurasi.');
    }

    /**
     * The log-facing reason. Never rendered to a caller.
     */
    public function alasan(): string
    {
        return $this->alasan;
    }
}
