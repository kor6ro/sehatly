<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

class ApiResponse
{
    /**
     * Build a successful envelope: {"success":true,"data":<data>,"message":<message>}.
     *
     * The key order is fixed and load-bearing: the mobile client (todo 45) and the
     * generated OpenAPI document (todo 53) both describe the envelope positionally,
     * so `data` must sit between `success` and `message` exactly as written here.
     */
    public static function success(mixed $data = null, string $message = '', int $status = 200): JsonResponse
    {
        return new JsonResponse([
            'success' => true,
            'data' => $data,
            'message' => $message,
        ], $status);
    }

    /**
     * Build a failure envelope: {"success":false,"message":<message>,"errors":<errors>}.
     *
     * `errors` is cast to an object so an empty error set encodes as `{}` rather than
     * `[]`. Without the cast a field-keyed map would flip between a JSON array and a
     * JSON object depending on whether it happened to be empty, and every client
     * would need a second shape check. A failure with no field-level detail is a real
     * case here: 401, 403, 404 and the sanitized 500 all pass an empty map.
     */
    public static function error(string $message, array $errors = [], int $status = 400): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'message' => $message,
            'errors' => (object) $errors,
        ], $status);
    }
}
