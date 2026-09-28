<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One pharmacy's shelf for one drug, or the absence of a chosen one.
 *
 * ## `recorded` and `jumlah_stok` are DIFFERENT facts and both are published
 *
 * A pharmacy that has never been stocked with a drug has NO `apotek_stok` row,
 * while one that has been stocked and sold out has a row reading zero. The DDL
 * makes the first reachable and the second too, and they are different answers
 * to "can I get this here": a row of zero means "ask again tomorrow", no row
 * means "this pharmacy does not carry it". Publishing only `jumlah_stok` would
 * render both as `0` and tell a patient to keep checking a pharmacy that will
 * never have it.
 *
 * `jumlah_stok` is a SIGNED `INT` (`telemedicine_test.sql:833`), so a negative
 * value is representable and is reported AS STORED rather than clamped to zero
 * here. The service is what prevents it (`ApotekStokService::kurangi()`), and a
 * client that receives a negative number is being told a fact about the
 * database, not being handed a bug to paper over.
 *
 * @property-read array<string, mixed> $resource
 */
class StokObatResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
