<?php

declare(strict_types=1);

namespace Tests\Contract\Support;

use Illuminate\Testing\TestResponse;

/**
 * Drives real HTTP requests at the running application and validates what comes
 * back against what `docs/openapi.yaml` says that operation should answer.
 *
 * ## Why every assertion here is a real request
 *
 * A conformance test that reads the document and asserts the document is
 * internally consistent proves nothing about the server; it would pass on a
 * repository where every route 500s. Nothing in this class reads a response from
 * anywhere but a live `$this->getJson()` / `postJson()`, and nothing validates a
 * body that was constructed rather than received.
 *
 * ## Path parameters are bound to values the ROUTE accepts, not to literals
 *
 * `/api/v1/dokter/{dokter}` is `whereNumber('dokter')` and
 * `/api/v1/surat-keterangan/{nomor_surat}/verify` is a free string, so a single
 * placeholder strategy cannot drive both. {@see self::concretePath()} takes the
 * value table below, which records the shape each parameter actually accepts.
 * Substituting a non-numeric value into a `whereNumber` segment would produce a
 * router 404 and the operation would appear to "pass" its 404 assertion while its
 * 200 was never reached -- a test that proves the wrong thing.
 */
final class LiveRequest
{
    /**
     * Substitutions for path parameters, chosen to be VALID for each segment's
     * own constraint so the router matches and the CONTROLLER runs.
     *
     * The value is a plausible identifier that does not exist, which is what
     * makes a `404` the honest expectation for the reads and a `422` for the
     * writes whose request body is absent.
     *
     * @var array<string, string>
     */
    public const PATH_PARAMETERS = [
        'id' => '999999999',
        'dokter' => '999999999',
        'deviceId' => 'contract-suite-absent-device',
        'nomor_surat' => 'SK/CONTRACT-SUITE-ABSENT',
        // `pembayaran.gateway` is `ENUM('midtrans','xendit','doku','flip') NULL`
        // (telemedicine_test.sql:965) and the route constrains the segment to
        // that enum, so any other value is a router 404 and the service would
        // never run. `midtrans` is a real member, not an invented one.
        'gateway' => 'midtrans',
    ];

    /**
     * Substitute every `{param}` in a documented path with a value the route
     * accepts.
     */
    public static function concretePath(string $path): string
    {
        return (string) preg_replace_callback(
            '/\{([A-Za-z_][A-Za-z0-9_]*)\}/',
            static function (array $m): string {
                if (! array_key_exists($m[1], self::PATH_PARAMETERS)) {
                    throw new \RuntimeException(sprintf(
                        'No path-parameter substitution is recorded for "{%s}". Add one to '
                        .'LiveRequest::PATH_PARAMETERS rather than guessing a value here.',
                        $m[1],
                    ));
                }

                return self::PATH_PARAMETERS[$m[1]];
            },
            $path,
        );
    }

    /**
     * The decoded body as an OBJECT GRAPH, not an associative array.
     *
     * The distinction is load-bearing: `{}` and `[]` both become `array` under an
     * associative decode, and `ApiResponse::error()` casts an empty error set to
     * `(object) []` specifically so it encodes as `{}`. Reading the raw bytes and
     * decoding without the `true` flag is what lets a test tell the two apart.
     *
     * @return array{object: mixed, raw: string}
     */
    public static function decode(TestResponse $response): array
    {
        $raw = (string) $response->getContent();
        $decoded = json_decode($raw);

        return ['object' => $decoded, 'raw' => $raw];
    }

    /**
     * Validate a response against the schema the document publishes for the
     * status it actually answered.
     *
     * Returns the violation list. An empty list means the live body conforms to
     * the schema the document promises for that exact status -- which is the
     * only assertion in this suite that can falsify the contract.
     *
     * @param  array<string, mixed>  $operation
     * @return list<string>
     */
    public static function validateAgainstDocument(
        TestResponse $response,
        array $operation,
        ?EnvelopeValidator $validator = null,
    ): array {
        $validator ??= new EnvelopeValidator;
        $status = (string) $response->getStatusCode();
        $ref = ContractSpec::schemaRefFor($operation, $status);

        if ($ref === null) {
            // The operation does not document this status at all. Reported as a
            // violation rather than waved through, so an UNDOCUMENTED status is a
            // conformance failure -- which is requirement (4) of this todo.
            return [sprintf(
                'the document publishes no %s response for this operation, but the live application answered one',
                $status,
            )];
        }

        ['object' => $body] = self::decode($response);

        return $validator->validate($body, ['$ref' => $ref]);
    }
}