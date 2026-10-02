<?php

namespace App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;

/**
 * The two API envelopes, and the one optional key a list response may carry.
 *
 * ## Key order is fixed, and `meta` is LAST on purpose
 *
 * ```
 * success -> {"success":true,"data":<data>,"message":<message>}
 * with meta -> {"success":true,"data":<data>,"message":<message>,"meta":<meta>}
 * failure  -> {"success":false,"message":<message>,"errors":<errors>}
 * failure with meta -> {"success":false,"message":<message>,"errors":<errors>,"meta":<meta>}
 * ```
 *
 * `data` sits between `success` and `message` because the mobile client (todo 45) and
 * the generated OpenAPI document (todo 53) both describe the envelope positionally.
 * `meta` is appended **after** `message` rather than inserted at position two so that
 * adding it cannot renumber the three existing keys: every envelope this class has
 * always produced is still byte-identical, which is what lets a *new* key be added
 * without a single existing client or test having to change.
 *
 * ## A response with no `meta` has NO `meta` key, not `"meta": null`
 *
 * The key is omitted entirely when `$meta === null`. A `"meta": null` would make every
 * non-list endpoint carry a fourth key whose value a client must null-check, and it
 * would break `ApiKernelTest::test_success_response_uses_the_exact_success_envelope`,
 * which asserts the raw body of a plain success response character for character.
 * Omission is the only shape in which "this response is not paginated" is
 * unambiguous.
 *
 * ## `meta` is the project's pagination block, and this class builds it
 *
 * The plan's todo 21 requires every list endpoint to answer
 * `?page=&per_page=` (capped at 100) and to return a
 * `meta: {current_page, last_page, total}` block. {@see pageMeta()} derives that block
 * from a `LengthAwarePaginator` so the two can never disagree about the key names, and
 * it adds `per_page`, `from` and `to` because a client rendering "1-15 of 47" needs
 * them and deriving them per controller is how three spellings appear in one codebase.
 *
 * A response that is a list but is deliberately **not** paginated - a signed-in
 * account's handful of devices, for instance - passes the same keys with the degenerate
 * values `current_page = 1` and `last_page = 1`. That is truthful rather than a special
 * case, and it is why `GET /api/v1/auth/devices` answers the same `meta` shape as
 * `GET /api/v1/pasien/alergi` even though one of them is a single page.
 *
 * ## Why `meta` is a plain array and not a resource
 *
 * It is derived, identical for every caller, and carries no model. Wrapping it in a
 * `JsonResource` would add a `$request` dependency and a `data` wrapper for no gain,
 * and the plan names a bare `meta` object.
 */
class ApiResponse
{
    /**
     * Build a successful envelope: {"success":true,"data":<data>,"message":<message>}.
     *
     * The key order is fixed and load-bearing: the mobile client (todo 45) and the
     * generated OpenAPI document (todo 53) both describe the envelope positionally,
     * so `data` must sit between `success` and `message` exactly as written here.
     *
     * @param  array<string, mixed>|null  $meta  appended as the fourth key when not null
     */
    public static function success(mixed $data = null, string $message = '', int $status = 200, ?array $meta = null): JsonResponse
    {
        $payload = [
            'success' => true,
            'data' => $data,
            'message' => $message,
        ];

        if ($meta !== null) {
            $payload['meta'] = $meta;
        }

        return new JsonResponse($payload, $status);
    }

    /**
     * Build a failure envelope: {"success":false,"message":<message>,"errors":<errors>}.
     *
     * `errors` is cast to an object so an empty error set encodes as `{}` rather than
     * `[]`. Without the cast a field-keyed map would flip between a JSON array and a
     * JSON object depending on whether it happened to be empty, and every client
     * would need a second shape check. A failure with no field-level detail is a real
     * case here: 401, 403, 404 and the sanitized 500 all pass an empty map.
     *
     * `$meta` is the same optional fourth key {@see success()} carries, and it is
     * omitted entirely when null so every pre-existing failure body is unchanged
     * byte for byte. It exists for two measured facts a client has to act on:
     * `retry_after` on a 429 (seconds until the bucket frees, mirroring the
     * `Retry-After` header) and `sisa_percobaan` on an OTP-verify 422/429 (how many
     * guesses the code has left before it is burned). Both are numbers the server
     * computed, never strings a client is expected to parse out of `message`.
     *
     * @param  array<string, mixed>|null  $meta  appended as the fourth key when not null
     */
    public static function error(string $message, array $errors = [], int $status = 400, ?array $meta = null): JsonResponse
    {
        $payload = [
            'success' => false,
            'message' => $message,
            'errors' => (object) $errors,
        ];

        if ($meta !== null) {
            $payload['meta'] = $meta;
        }

        return new JsonResponse($payload, $status);
    }

    /**
     * The project-wide pagination block, derived from a paginator.
     *
     * The three keys the plan names - `current_page`, `last_page`, `total` - come
     * straight off the paginator. `per_page` is the page size that was actually
     * applied rather than the one the caller asked for, so it stays correct after the
     * 100 cap has clamped the request. `from` and `to` are `null` on an empty page
     * rather than `0`, because "no rows" has no first and last row.
     *
     * @return array<string, int|null>
     */
    public static function pageMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ];
    }

    /**
     * The same block for a deliberately unpaginated list, which is a single page of
     * everything.
     *
     * Used by `GET /api/v1/auth/devices`: a signed-in account has a handful of
     * devices, so there is nothing to page, but the response still has to carry the
     * project-wide `meta` shape so a client parses one list envelope rather than two.
     *
     * @return array<string, int|null>
     */
    public static function singlePageMeta(int $total): array
    {
        return [
            'current_page' => 1,
            'last_page' => 1,
            'per_page' => $total,
            'total' => $total,
            'from' => $total > 0 ? 1 : null,
            'to' => $total > 0 ? $total : null,
        ];
    }
}
