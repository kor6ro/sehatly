<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Auth\IssuedOtp;
use App\Services\Auth\OtpSender;

/**
 * An in-memory {@see OtpSender} that records what it was asked to deliver.
 *
 * ## Why this exists instead of reading the log
 *
 * `OtpService::issue()` returns an {@see IssuedOtp} whose plaintext is
 * deliberately unreachable outside the `local` environment, and `phpunit.xml` sets
 * `APP_ENV=testing`. So a test that wants to complete an OTP flow has exactly two
 * options: flip the environment, or observe the delivery. Observing the delivery is
 * chosen here because it is what actually happens in production -- the code reaches the
 * recipient through this channel, not through the response -- and because flipping the
 * environment would exercise a path no production request uses.
 *
 * The binding is replaced in `AuthFlowTest::beforeEach` with
 * `$this->app->instance(OtpSender::class, ...)`, which is the same binding
 * `AppServiceProvider::configureOtpDelivery()` sets in production. A deployment that
 * swaps in a real gateway replaces that one line and every test in that file keeps
 * working, which is the property that makes the interface worth having.
 */
final class FakeOtpSender implements OtpSender
{
    /**
     * Every delivery, in order.
     *
     * @var list<array{recipient: string, kode: string, purpose: string}>
     */
    public array $sent = [];

    public function send(string $tujuan, string $kode, string $purpose): void
    {
        $this->sent[] = [
            'recipient' => $tujuan,
            'kode' => $kode,
            'purpose' => $purpose,
        ];
    }

    /**
     * The most recent code delivered for `$purpose`, or `null` if there was none.
     */
    public function lastKodeFor(string $purpose): ?string
    {
        $matching = $this->kodesFor($purpose);

        return $matching === [] ? null : end($matching);
    }

    /**
     * Every code delivered for `$purpose`, in delivery order.
     *
     * @return list<string>
     */
    public function kodesFor(string $purpose): array
    {
        $kodes = [];

        foreach ($this->sent as $delivery) {
            if ($delivery['purpose'] === $purpose) {
                $kodes[] = $delivery['kode'];
            }
        }

        return $kodes;
    }

    /**
     * How many codes were delivered for `$purpose`.
     */
    public function countFor(string $purpose): int
    {
        return count($this->kodesFor($purpose));
    }
}
