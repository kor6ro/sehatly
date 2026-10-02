<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Telepon;
use Illuminate\Console\Command;

/**
 * One-shot, idempotent backfill of `users.no_telepon` into the canonical local
 * `08…` form.
 *
 * ## Why this exists
 *
 * Before F01, `0812…` and `+62812…` were two accounts for one phone: the
 * `unique` rule and every lookup matched the submitted string exactly. The
 * application now normalises on the way in and on the way out
 * ({@see Telepon}), which makes every NEW account canonical - but a row already
 * stored as `+62812…` would become unreachable, because the lookup folds the
 * caller's input to `08…` and the stored row is still `+62812…`. This command
 * folds the existing rows, and is the reason the fix does not need a data
 * migration (and therefore does not touch `telemedicine_test.sql`).
 *
 * ## It is safe to re-run, and it refuses to create a duplicate
 *
 * Rows already canonical are skipped. A row whose canonical form is already
 * taken by a DIFFERENT account is reported and left alone rather than handed to
 * the unique index: two accounts really do exist for one number in that case,
 * and choosing which to keep is a product decision, not a sweep's. The exit
 * code stays 0 for that case - the run did its job and named the rows that need
 * a human - so only an unexpected failure is non-zero.
 *
 * `--dry-run` reports what would change and writes nothing.
 *
 * Writes go through the model rather than the query builder so the global
 * `AuditObserver` records the change: `no_telepon` is a PII column and a silent
 * bulk update would be the one edit to it with no audit row.
 */
class NormalisasiTeleponCommand extends Command
{
    /** @var string */
    protected $signature = 'sehatly:normalisasi-telepon {--dry-run : Laporkan tanpa menulis}';

    /** @var string */
    protected $description = 'Normalisasi users.no_telepon ke bentuk kanonik lokal 08... (idempoten)';

    public function handle(): int
    {
        $kering = (bool) $this->option('dry-run');
        $diperiksa = 0;
        $diubah = 0;
        $tabrakan = 0;

        User::withTrashed()
            ->orderBy('id')
            ->chunkById(200, function ($pengguna) use (&$diperiksa, &$diubah, &$tabrakan, $kering): void {
                foreach ($pengguna as $user) {
                    $diperiksa++;

                    $lama = (string) $user->no_telepon;
                    $baru = Telepon::normalisasi($lama);

                    if ($baru === null || $baru === $lama) {
                        continue;
                    }

                    $bentrok = User::withTrashed()
                        ->where('no_telepon', $baru)
                        ->whereKeyNot($user->getKey())
                        ->exists();

                    if ($bentrok) {
                        $tabrakan++;

                        $this->warn(sprintf(
                            'Tabrakan: user #%d [%s] -> [%s] sudah dipakai akun lain; dilewati.',
                            (int) $user->getKey(),
                            $lama,
                            $baru,
                        ));

                        continue;
                    }

                    if ($kering) {
                        $this->line(sprintf('Akan diubah: user #%d [%s] -> [%s].', (int) $user->getKey(), $lama, $baru));
                        $diubah++;

                        continue;
                    }

                    $user->no_telepon = $baru;
                    $user->save();

                    $diubah++;
                }
            });

        $this->info(sprintf(
            '%s: %d baris diperiksa, %d %s, %d tabrakan.',
            $kering ? 'Dry-run' : 'Selesai',
            $diperiksa,
            $diubah,
            $kering ? 'akan diubah' : 'diubah',
            $tabrakan,
        ));

        return self::SUCCESS;
    }
}
