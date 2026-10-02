<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DokterLibur;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One `dokter_libur` row: a date and an optional reason, and nothing else.
 *
 * The table has exactly three meaningful columns (`dokter_id`, `tanggal`,
 * `alasan`, telemedicine_test.sql:490-496) and no timestamps at all, so a
 * resource that published a `dibuat_at` would be inventing one. There is no
 * `mulai`/`selesai` key because the schema cannot represent a partial day - a
 * client that wants to render "sehari penuh" reads that from the absence of any
 * time field, and the F14 pattern states it in the UI copy.
 *
 * @property-read DokterLibur $resource
 */
class AdminLiburResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->getKey(),
            'dokter_id' => $this->resource->dokter_id,
            'tanggal' => $this->resource->tanggal?->format('Y-m-d'),
            'alasan' => $this->resource->alasan,
        ];
    }
}
