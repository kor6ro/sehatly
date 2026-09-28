<?php

declare(strict_types=1);

namespace App\Services\SuratKeterangan;

/**
 * The source of a medical letter's QR verification token.
 *
 * ## Why an interface and not a `Str::uuid()` call inside the service
 *
 * `surat_keterangan.qr_token` is `VARCHAR(100) NOT NULL` (telemedicine_test.sql:592)
 * with **no UNIQUE index and no other index at all**, so MySQL will not stop two
 * letters from carrying the same token. The application-level duplicate check plus a
 * bounded retry is therefore the ONLY defence the schema permits - and the only way to
 * test that defence is to make the generator return a value that is already taken.
 *
 * A test that cannot substitute the generator would have to plant a row whose token
 * happened to equal the next `Str::uuid()`, which is a 122-bit guess. Injecting the
 * source is what turns "the retry exists" from a claim into a measurement, and it is
 * the same reason `NomorDokumen` takes a `$urutan` callable: candidates must be
 * PREDICTABLE under test or a genuine collision cannot be forced at all.
 *
 * ## The default is a v4 UUID because the token is a BEARER SECRET
 *
 * The token is what the QR code carries and what a scanner presents, so it must not be
 * derivable from the document number, the issuing doctor or the clock. A v4 UUID has
 * 122 free bits from the system's CSPRNG, which is what the plan's "never a guessable
 * value" asks for, and it is 36 characters against a 100-character column, so the value
 * stored is byte-for-byte the value the QR carries - no padding, no truncation, and
 * therefore no second string that could collide with a different one.
 */
interface QrTokenGenerator
{
    /**
     * The next verification token.
     *
     * Implementations are expected to be effectively unguessable and to return
     * DIFFERENT values on successive calls; the retry in
     * {@see SuratKeteranganService} exists for the case where one does collide with an
     * existing row, not as a substitute for a generator that repeats itself.
     */
    public function next(): string;
}
