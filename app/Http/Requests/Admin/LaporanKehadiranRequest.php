<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

/**
 * `GET /admin/laporan/kehadiran` - the attendance report's range.
 *
 * No `dokter_id` for the same reason as the revenue report: the request would
 * need a definition of "attendance for this doctor" that the contract does not
 * name. Adding one later is a one-line rule here plus one `where` in the
 * service; guessing one now would bake an unapproved semantic into the contract.
 */
class LaporanKehadiranRequest extends LaporanRangeRequest {}
