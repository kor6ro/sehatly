<?php

declare(strict_types=1);

namespace App\Services\Notifikasi;

use App\Enums\PengingatStatus;
use App\Enums\ZonaWaktu;
use App\Models\Pengingat;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * CRUD for the caller's own `pengingat` rows.
 *
 * ## Every lookup is scoped to the caller, and a miss is a 404
 *
 * `PengingatController` is the only route surface and each query is
 * `where('user_id', $caller)`. A sequential `BIGINT` id owned by another
 * account is not found rather than forbidden - the same cross-tenant existence
 * oracle `NotifikasiController` refuses to open.
 *
 * ## `waktu` is normalised HERE, not trusted from the form
 *
 * The request validates every entry as `HH:MM`; this service then sorts the
 * list and removes duplicates, because two identical times in one reminder are
 * two dispatch attempts in the same minute and the idempotency key would make
 * the second one a no-op that still looked like a duplicate row in the API.
 * Sorted order also makes the stored JSON byte-stable, which is what lets the
 * test compare what it sent with what comes back.
 *
 * ## The write goes through the MODEL
 *
 * `Pengingat` hangs off `users`, so the global `AuditObserver` audits it. A
 * query-builder write would be the one write in this table with no
 * `audit_log` row.
 */
final class PengingatService
{
    /**
     * The caller's reminders, newest `tanggal_mulai` first.
     *
     * @param  array{status?: string|null, page?: int|null, per_page?: int|null}  $filter
     * @return LengthAwarePaginator<int, Pengingat>
     */
    public function daftar(User $user, array $filter): LengthAwarePaginator
    {
        $query = Pengingat::query()->where('user_id', (int) $user->getKey());

        if (($filter['status'] ?? null) !== null) {
            $query->where('status', (string) $filter['status']);
        }

        return $query
            ->orderByDesc('tanggal_mulai')
            ->orderByDesc('id')
            ->paginate(
                (int) ($filter['per_page'] ?? 15),
                ['*'],
                'page',
                (int) ($filter['page'] ?? 1),
            );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function buat(User $user, array $data): Pengingat
    {
        return DB::transaction(function () use ($user, $data): Pengingat {
            $baris = new Pengingat;
            $baris->user_id = (int) $user->getKey();
            $this->isi($baris, $data, membuat: true);
            $baris->save();

            return $baris;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ModelNotFoundException when the reminder is not the caller's
     */
    public function ubah(User $user, int $id, array $data): Pengingat
    {
        return DB::transaction(function () use ($user, $id, $data): Pengingat {
            $baris = $this->untukUser($user, $id);
            $this->isi($baris, $data, membuat: false);
            $baris->save();

            return $baris;
        });
    }

    /**
     * @throws ModelNotFoundException when the reminder is not the caller's
     */
    public function hapus(User $user, int $id): void
    {
        DB::transaction(function () use ($user, $id): void {
            $this->untukUser($user, $id)->delete();
        });
    }

    /**
     * One reminder of the caller's, or a 404.
     *
     * @throws ModelNotFoundException
     */
    public function untukUser(User $user, int $id): Pengingat
    {
        $baris = Pengingat::query()
            ->where('user_id', (int) $user->getKey())
            ->whereKey($id)
            ->first();

        if ($baris === null) {
            throw (new ModelNotFoundException)->setModel(Pengingat::class, [$id]);
        }

        return $baris;
    }

    /**
     * A `waktu` list normalised to sorted, unique `HH:MM` strings.
     *
     * @param  list<string>  $waktu
     * @return list<string>
     */
    public static function rapikanWaktu(array $waktu): array
    {
        $bersih = array_values(array_unique(array_map(
            static fn (string $jam): string => substr(trim($jam), 0, 5),
            $waktu,
        )));

        sort($bersih, SORT_STRING);

        return $bersih;
    }

    /**
     * Apply the validated payload to a row.
     *
     * On create, `status` is always `aktif` (the DDL default made explicit) and
     * `zona_waktu` falls back to the DDL default; a client cannot create a
     * non-active reminder in one step, because "created already paused" is a
     * state no screen offers and the create request does not accept.
     *
     * @param  array<string, mixed>  $data
     */
    private function isi(Pengingat $baris, array $data, bool $membuat): void
    {
        foreach (['jenis', 'judul', 'keterangan', 'obat_id', 'booking_id', 'dosis', 'jumlah_per_hari', 'lama_hari', 'zona_waktu'] as $kolom) {
            if (array_key_exists($kolom, $data)) {
                $baris->{$kolom} = $data[$kolom];
            }
        }

        if (array_key_exists('tanggal_mulai', $data)) {
            $baris->tanggal_mulai = $data['tanggal_mulai'];
        }

        if (array_key_exists('waktu', $data)) {
            $baris->waktu = self::rapikanWaktu((array) $data['waktu']);
        }

        if ($membuat) {
            $baris->status = PengingatStatus::Aktif->value;
        } elseif (array_key_exists('status', $data)) {
            $baris->status = $data['status'];
        }

        if ($baris->zona_waktu === null || $baris->zona_waktu === '') {
            $baris->zona_waktu = ZonaWaktu::DEFAULT;
        }
    }
}
