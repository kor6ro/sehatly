<?php

declare(strict_types=1);

namespace App\Services\Notifikasi;

use App\Enums\JamTenangMode;
use App\Enums\PreferensiNotifikasiTipe;
use App\Enums\ZonaWaktu;
use App\Models\PreferensiNotifikasi;
use App\Models\PreferensiNotifikasiTipe as BarisTipe;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The one answer to "what are this account's notification preferences?".
 *
 * ## Lazy-upsert: an absent row is a DEFAULT, not a missing preference
 *
 * `GET` never writes. A user with no `preferensi_notifikasi` row gets the
 * effective defaults - quiet hours OFF, `setiap_hari`, `21:00`-`06:00`,
 * `Asia/Jakarta` - and a user with no row for a produced type gets
 * `push_aktif = true`, which is the DDL's own default
 * (`telemedicine_test.sql:1388`). `PUT` materialises only what it is given, so
 * toggling one type does not invent four rows.
 *
 * ## The matrix is the four PRODUCED types, and in-app is not a column
 *
 * `lab`/`promo`/`sistem` are not offered. In-app delivery is unconditional: the
 * only switch the schema stores is `preferensi_notifikasi_tipe.push_aktif`.
 *
 * ## Quiet hours are read on the PREFERENCE's clock
 *
 * The window is a pair of `TIME` wall clocks plus a zone, so "22:00" is
 * meaningless without it. {@see dalamJamTenang()} converts the *instant* it is
 * given into the preference's own `zona_waktu` before comparing, and a window
 * that wraps midnight (`21:00`-`06:00`, the default) is the normal shape rather
 * than an error: quiet when `t >= mulai` OR `t < selesai`.
 */
final class PreferensiNotifikasiService
{
    /**
     * The effective preferences for `$user`, defaults folded in.
     *
     * @return array{
     *     jam_tenang_aktif: bool,
     *     jam_tenang_mode: string,
     *     jam_tenang_mulai: string,
     *     jam_tenang_selesai: string,
     *     zona_waktu: string,
     *     push: array<string, bool>
     * }
     */
    public function efektif(User $user): array
    {
        $baris = $this->baris($user);

        return [
            'jam_tenang_aktif' => $baris !== null && (bool) $baris->jam_tenang_aktif,
            'jam_tenang_mode' => $baris?->jam_tenang_mode ?? JamTenangMode::SetiapHari->value,
            'jam_tenang_mulai' => $this->jamPendek($baris?->jam_tenang_mulai) ?? '21:00',
            'jam_tenang_selesai' => $this->jamPendek($baris?->jam_tenang_selesai) ?? '06:00',
            'zona_waktu' => $baris?->zona_waktu ?? ZonaWaktu::DEFAULT,
            'push' => $this->matriks($user),
        ];
    }

    /**
     * Persist what the caller sent, then answer the resulting effective state.
     *
     * Everything is optional: the endpoint is a PUT of a form, and a client that
     * sends only `push.chat = false` must not silently reset the quiet hours it
     * did not mention. Each block writes only when it has something to write.
     *
     * @param  array<string, mixed>  $data
     * @return array{
     *     jam_tenang_aktif: bool,
     *     jam_tenang_mode: string,
     *     jam_tenang_mulai: string,
     *     jam_tenang_selesai: string,
     *     zona_waktu: string,
     *     push: array<string, bool>
     * }
     */
    public function simpan(User $user, array $data): array
    {
        DB::transaction(function () use ($user, $data): void {
            $adaJamTenang = array_key_exists('jam_tenang_aktif', $data)
                || array_key_exists('jam_tenang_mode', $data)
                || array_key_exists('jam_tenang_mulai', $data)
                || array_key_exists('jam_tenang_selesai', $data)
                || array_key_exists('zona_waktu', $data);

            if ($adaJamTenang) {
                $baris = $this->baris($user) ?? new PreferensiNotifikasi;
                $baris->user_id = (int) $user->getKey();

                if (array_key_exists('jam_tenang_aktif', $data)) {
                    $baris->jam_tenang_aktif = (bool) $data['jam_tenang_aktif'];
                }

                if (array_key_exists('jam_tenang_mode', $data)) {
                    $baris->jam_tenang_mode = (string) $data['jam_tenang_mode'];
                }

                if (array_key_exists('jam_tenang_mulai', $data)) {
                    $baris->jam_tenang_mulai = (string) $data['jam_tenang_mulai'];
                }

                if (array_key_exists('jam_tenang_selesai', $data)) {
                    $baris->jam_tenang_selesai = (string) $data['jam_tenang_selesai'];
                }

                if (array_key_exists('zona_waktu', $data)) {
                    $baris->zona_waktu = (string) $data['zona_waktu'];
                }

                $baris->save();
            }

            foreach ((array) ($data['push'] ?? []) as $tipe => $aktif) {
                $baris = BarisTipe::query()
                    ->where('user_id', (int) $user->getKey())
                    ->where('tipe', (string) $tipe)
                    ->first() ?? new BarisTipe;

                $baris->user_id = (int) $user->getKey();
                $baris->tipe = (string) $tipe;
                $baris->push_aktif = (bool) $aktif;
                $baris->save();
            }
        });

        return $this->efektif($user);
    }

    /**
     * May this user receive a PUSH for this produced notification type?
     *
     * An absent row is TRUE - "no row = default on", the schema's convention.
     * A type outside the matrix (the scheduler's `sistem` reminder notice) has
     * no switch to consult and is therefore allowed here; the caller still has
     * consent and quiet hours to check.
     */
    public function pushAktif(User $user, string $tipe): bool
    {
        if (! in_array($tipe, PreferensiNotifikasiTipe::nilai(), true)) {
            return true;
        }

        $baris = BarisTipe::query()
            ->where('user_id', (int) $user->getKey())
            ->where('tipe', $tipe)
            ->first();

        return $baris === null || (bool) $baris->push_aktif;
    }

    /**
     * Is `$momen` inside this user's quiet hours?
     *
     * No row, or `jam_tenang_aktif = false`, answers FALSE. `hari_kerja` is
     * Monday-Friday on the preference's own clock; `setiap_hari` and `kustom`
     * apply every day (the approved schema has no custom-day column - see
     * {@see JamTenangMode}). The window wraps midnight when `mulai > selesai`,
     * which is the default shape and is handled rather than rejected.
     */
    public function dalamJamTenang(User $user, CarbonInterface $momen): bool
    {
        $baris = $this->baris($user);

        if ($baris === null || ! (bool) $baris->jam_tenang_aktif) {
            return false;
        }

        $lokal = $momen->setTimezone($baris->zona_waktu);

        if ($baris->jam_tenang_mode === JamTenangMode::HariKerja->value && $lokal->dayOfWeekIso > 5) {
            return false;
        }

        $sekarang = $lokal->format('H:i');
        $mulai = $this->jamPendek($baris->jam_tenang_mulai) ?? '21:00';
        $selesai = $this->jamPendek($baris->jam_tenang_selesai) ?? '06:00';

        if ($mulai <= $selesai) {
            return $sekarang >= $mulai && $sekarang < $selesai;
        }

        return $sekarang >= $mulai || $sekarang < $selesai;
    }

    /**
     * The per-type matrix, every produced type present, absent rows folded to
     * `true`.
     *
     * @return array<string, bool>
     */
    private function matriks(User $user): array
    {
        $tersimpan = BarisTipe::query()
            ->where('user_id', (int) $user->getKey())
            ->pluck('push_aktif', 'tipe');

        $matriks = [];

        foreach (PreferensiNotifikasiTipe::nilai() as $tipe) {
            $matriks[$tipe] = ! $tersimpan->has($tipe) || (bool) $tersimpan->get($tipe);
        }

        return $matriks;
    }

    private function baris(User $user): ?PreferensiNotifikasi
    {
        return PreferensiNotifikasi::query()
            ->where('user_id', (int) $user->getKey())
            ->first();
    }

    /**
     * A `TIME` column as `H:i`, or null when there is no value.
     *
     * The column hands back `HH:MM:SS`; the API publishes `HH:MM` because that
     * is what a human types into a time input, and the seconds are always `00`
     * for a value the validator accepted.
     */
    private function jamPendek(mixed $nilai): ?string
    {
        if ($nilai === null || $nilai === '') {
            return null;
        }

        return substr((string) $nilai, 0, 5);
    }
}
