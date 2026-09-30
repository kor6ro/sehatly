<?php

declare(strict_types=1);

namespace App\Support\Security;

use RuntimeException;

/**
 * F-005: a non-local boot whose OTP or push transport is the log.
 *
 * Both `LogOtpSender` and `LogPushDispatcher` are real, useful implementations -
 * in `local` and `testing`, where the log IS the delivery record. Outside those two
 * environments they are a silent outage: a log line is not a WhatsApp message and
 * not a push, and a deployment running them looks healthy while nobody can log in
 * (the OTP never arrives) and no device is ever notified.
 *
 * The message names each misconfigured variable, and never a credential value.
 */
final class PengirimanLogDiProduksiException extends RuntimeException {}
