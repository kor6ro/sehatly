<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Booking\BookingService;
use Illuminate\Console\Command;

/**
 * Flip stale `menunggu_pembayaran` bookings to `kadaluarsa`.
 *
 * Exit codes: 0 the sweep ran (the flipped count is informational, so zero
 * flipped rows is still success); a run that cannot understand its inputs
 * must never look green, so any exception leaves the code non-zero by
 * falling through to the framework's failure handling.
 */
class BookingKadaluarsaCommand extends Command
{
    /** @var string */
    protected $signature = 'booking:kadaluarsa';

    /** @var string */
    protected $description = 'Tandai booking menunggu_pembayaran yang sudah lewat menjadi kadaluarsa';

    public function handle(BookingService $layanan): int
    {
        $baris = $layanan->kadaluarsa();

        $this->info("Booking kedaluwarsa: {$baris} baris.");

        return self::SUCCESS;
    }
}
