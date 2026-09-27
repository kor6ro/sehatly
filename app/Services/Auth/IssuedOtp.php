<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Carbon\CarbonInterface;

/**
 * A freshly minted one-time code, plus the only rule that governs whether its
 * plaintext may leave the server.
 *
 * ## Why the plaintext is carried at all
 *
 * The code has to exist in memory between {@see OtpService::issue()} and the
 * {@see OtpSender}, because the sender is the delivery channel. Carrying it in a
 * dedicated object rather than returning a bare `string` is what makes the
 * disclosure decision enforceable: the controller receives an `IssuedOtp`, and the
 * only way to obtain the plaintext is {@see plainTextForClient()}, which consults the
 * environment. There is no `$kode` property to reach for by accident.
 *
 * ## `__debugInfo()` exists so a dump cannot leak it
 *
 * An `dd()`, a `dump()` inside a debugger, or a var-dumped exception trace prints
 * public properties. `plainText` is private and the public surface carries only
 * non-secret facts, and `__debugInfo()` re-declares that explicitly so adding a
 * public property later cannot silently publish the code. The expiry is included
 * because it is genuinely useful in a trace and is not a secret.
 *
 * The expiry is typed `CarbonInterface`, not `Illuminate\Support\Carbon`:
 * `AppServiceProvider::configureDefaults()` calls `Date::use(CarbonImmutable::class)`,
 * so `now()` hands back a `Carbon\CarbonImmutable` and an `Illuminate\Support\Carbon`
 * parameter would be a TypeError on every single call.
 */
final class IssuedOtp
{
    public function __construct(
        public readonly int $userId,
        public readonly string $tujuan,
        private readonly string $plainText,
        public readonly CarbonInterface $kedaluwarsaAt,
    ) {}

    /**
     * The plaintext code, but only when the application is running in `local`.
     *
     * This is the single place the plan's "return the code in the response only when
     * `app()->environment('local')`" rule lives. Every caller of
     * {@see OtpService::issue()} therefore inherits it without being able to opt out,
     * which is what makes "must NOT return the OTP in a non-local environment" a
     * property of the system rather than a rule each endpoint has to remember.
     *
     * `phpunit.xml` sets `APP_ENV=testing`, so the suite runs the *refusing* branch
     * and cannot accidentally pass by running in `local`.
     */
    public function plainTextForClient(): ?string
    {
        return app()->environment('local') ? $this->plainText : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'userId' => $this->userId,
            'tujuan' => $this->tujuan,
            'kedaluwarsaAt' => $this->kedaluwarsaAt,
        ];
    }
}
