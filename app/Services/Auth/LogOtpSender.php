<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Support\KontakMasker;
use Illuminate\Support\Facades\Log;

/**
 * The shipped {@see OtpSender}: writes the code to the application log.
 *
 * ## Why the log and not a response field
 *
 * The plan requires the plaintext code to be returned in the API response **only**
 * when `app()->environment('local')`, and the real SMS provider is out of scope. So
 * there is exactly one delivery channel in this build, and it is the log. A
 * developer reads `storage/logs/laravel.log`; a production deployment has no way to
 * read the code at all, which is the correct outcome rather than a limitation.
 *
 * The `local`-only exposure in the response is decided in {@see OtpService::issue()},
 * not here, so that the rule has one home. This class therefore always logs,
 * including under `local`, where the value is also in the response. That is
 * deliberate: the log is the audit trail, and an audit trail that omits records
 * because they were also returned somewhere is not an audit trail.
 *
 * The log channel is `Log::channel(config('logging.default'))` resolved at call time
 * through the facade, so a test that swaps the default channel sees the line, and no
 * channel name is hard-coded here.
 */
final class LogOtpSender implements OtpSender
{
    /**
     * The log level used for the code itself.
     *
     * `notice` rather than `info` or `debug`: `info` is routinely kept for weeks and
     * `debug` is routinely filtered out, and a six-digit code that expires in five
     * minutes is only useful while it is live. `notice` is above the noise floor
     * without being a warning.
     */
    private const LEVEL = 'notice';

    public function send(string $tujuan, string $kode, string $purpose): void
    {
        Log::log(self::LEVEL, 'sehatly.otp', [
            'tujuan' => $purpose,
            'penerima' => $this->mask($tujuan),
            'kode' => $kode,
        ]);
    }

    /**
     * Mask the destination in the log line, keeping it useful without republishing a
     * phone number or an email address into a file that is shipped off-box by every
     * log shipper in the stack.
     *
     * The RULE now lives in {@see KontakMasker}, because F-005's `FonnteOtpSender`
     * logs the same masked destination and two copies of a masking rule is two places
     * for the next edit to get wrong. This method stays so the call site and its
     * reasoning read where they always did.
     */
    private function mask(string $tujuan): string
    {
        return KontakMasker::mask($tujuan);
    }
}
