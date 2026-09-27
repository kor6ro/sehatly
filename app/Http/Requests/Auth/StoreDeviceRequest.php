<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Validation\Rule;

/**
 * Validates `POST /api/v1/auth/devices`.
 *
 * ## `device_id` is opaque and is never derived server-side
 *
 * `user_devices.device_id` is `VARCHAR(255)` and, per the plan's bare-column list, one
 * of the 25 columns that carries a reference-shaped name with **no foreign key**. It is
 * a client-supplied installation identifier, not a key into any table, and the same
 * value may legitimately be reused by the same app on a reinstall. Nothing infers it,
 * and the upsert is keyed on `uq_device (user_id, device_id)` (`:201`) -- the user id
 * is the part the server owns.
 *
 * ## The `platform` list is the DDL's, not a guess
 *
 * `user_devices.platform ENUM('android','ios','web')` at `telemedicine_test.sql:194`.
 * A fourth value such as `webview` would be MySQL 1264 at insert time, which is exactly
 * the failure mode a `Rule::in` here turns into a 422 instead.
 *
 * `AuthFlowTest` re-parses that ENUM out of `telemedicine_test.sql` with the project's
 * own `SqlSchemaParser` and asserts it equals this constant, so a change to the
 * reference file fails the suite rather than this rule quietly becoming wrong.
 *
 * ## `app_versi` is bounded by a regex as well as a length
 *
 * `app_versi` is `VARCHAR(20)`, so `max:20` alone would accept a 20-character string of
 * any bytes at all. The character class keeps it to what a version actually is:
 * digits, dots, and the separators a build string may contain.
 */
class StoreDeviceRequest extends AuthRequest
{
    /**
     * `user_devices.platform ENUM('android','ios','web')` at `telemedicine_test.sql:194`.
     *
     * @var list<string>
     */
    public const PLATFORM = ['android', 'ios', 'web'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'device_id' => ['required', 'string', 'min:3', 'max:255'],
            'platform' => ['required', 'string', Rule::in(self::PLATFORM)],
            'fcm_token' => ['nullable', 'string', 'max:255'],
            'app_versi' => ['nullable', 'string', 'max:20', 'regex:/^[0-9A-Za-z.+_-]+$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'device_id' => 'id perangkat',
            'platform' => 'platform',
            'fcm_token' => 'fcm token',
            'app_versi' => 'versi aplikasi',
        ];
    }
}
