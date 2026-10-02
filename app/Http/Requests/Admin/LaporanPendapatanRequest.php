<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

/**
 * `GET /admin/laporan/pendapatan` - the revenue report's range.
 *
 * No `dokter_id`: an invoice is addressed to a `pasien`, and it is not joined to
 * the booking that may have produced it (the link is polymorphic through
 * `invoice.referensi_tipe`/`referensi_id`). Offering a doctor filter would mean
 * inventing a join and a definition of "this invoice belongs to this doctor",
 * which no contract asks for and which a referral or a future non-booking
 * invoice would make ambiguous.
 */
class LaporanPendapatanRequest extends LaporanRangeRequest {}
