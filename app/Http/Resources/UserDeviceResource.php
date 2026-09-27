<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\UserDevice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of `user_devices`, for the authenticated account only.
 *
 * ## The surrogate `id` is not published
 *
 * `user_devices` has a `BIGINT UNSIGNED AUTO_INCREMENT` surrogate `id` *and* a
 * `UNIQUE KEY uq_device (user_id, device_id)` (`:201`). The pair is the identity the
 * API addresses a device by -- `DELETE /api/v1/auth/devices/{deviceId}` takes the
 * `device_id` string -- so the surrogate is omitted. Publishing it would invite a
 * client to key on a number that is not stable across environments and is not what
 * `uq_device` guarantees.
 *
 * `user_id` is omitted for the same reason from the caller's point of view: the list is
 * always the caller's own, so echoing which account it belongs to is noise.
 *
 * ## `fcm_token` is returned because the client just supplied it
 *
 * It is the caller's own push registration token, the caller sent it in the request
 * that created or updated this row, and a device-management screen needs to show which
 * registration is active. It is not a credential for anything: possession of it grants
 * no access to this API.
 *
 * @property-read UserDevice $resource
 */
class UserDeviceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'device_id' => $this->resource->device_id,
            'platform' => $this->resource->platform,
            'fcm_token' => $this->resource->fcm_token,
            'app_versi' => $this->resource->app_versi,
            'aktif' => (bool) $this->resource->aktif,
            'last_active_at' => $this->resource->last_active_at?->toISOString(),
            'dibuat_at' => $this->resource->dibuat_at?->toISOString(),
        ];
    }
}
