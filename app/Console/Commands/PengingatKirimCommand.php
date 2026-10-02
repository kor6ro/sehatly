<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Notifikasi\PengingatPengirim;
use Illuminate\Console\Command;

/**
 * `php artisan pengingat:kirim` - one scheduler tick over every active
 * reminder.
 *
 * Scheduled every minute in `routes/console.php`. The command is a thin
 * adapter: all rules (window, time match, idempotency, consent, per-type
 * switch, quiet hours) live in {@see PengingatPengirim}, so the same behaviour
 * is reachable from a test without booting the scheduler and the command has no
 * second copy of any rule.
 *
 * The counters are printed because a scheduler tick is otherwise silent
 * (`notifikasi.push.*` lines only cover devices, not "why no row appeared"),
 * and `duplikat` is the expected steady state on a re-run inside the same
 * minute, not an error.
 */
class PengingatKirimCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'pengingat:kirim';

    /**
     * @var string
     */
    protected $description = 'Kirim pengingat aktif yang jatuh tempo sekarang (idempoten per pengingat, tanggal, waktu)';

    public function handle(PengingatPengirim $pengirim): int
    {
        $hasil = $pengirim->kirim();

        $this->info(sprintf(
            'Pengingat: %d diperiksa, %d dikirim, %d sudah pernah dikirim.',
            $hasil['diperiksa'],
            $hasil['dikirim'],
            $hasil['duplikat'],
        ));

        return self::SUCCESS;
    }
}
