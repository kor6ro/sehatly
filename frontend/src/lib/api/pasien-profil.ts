import { mutationOptions, queryOptions } from '@tanstack/react-query';
import { request } from '@/lib/http';
import { meQueryKey } from '@/lib/api/me';
import { queryClient } from '@/lib/query-client';
import type { PasienProfile } from '@/lib/api/types';

/**
 * `GET /api/v1/pasien/profil` and `PUT /api/v1/pasien/profil`
 *
 * One screen, one endpoint pair. The read already publishes `nama_lengkap` (from the
 * eager-loaded `user` relation), so a profile form does **not** need a second call to
 * `/me` to render a name.
 */

/**
 * The writable subset, and nothing else.
 *
 * `UpdatePasienProfileRequest::rules()` uses `sometimes` on every key and its
 * `pasienKeys()` returns `validated()` minus `nama_lengkap`, which lands exactly on this
 * list. The set is closed on the server: `tipe`, `status`, `no_telepon`, `nik`,
 * `jenis_kelamin`, `tanggal_lahir`, `nomor_rm`, `rhesus`, `is_meninggal`,
 * `nomor_kk`, `catatan_alergi` and `user_id` are all absent from the rules, so a payload
 * carrying them is validated and then dropped before the controller sees it.
 *
 * Two consequences the form has to respect:
 *
 * - `nik` and `nomor_kk` are **not** here. They are read-only to a patient, so the edit
 *   form renders the masked value and offers no input for it.
 * - `nama_lengkap` writes to `users`, not to `pasien`, in the same transaction. It is the
 *   one key of this payload that lives on a second row.
 */
export type UpdateProfilInput = {
    nama_lengkap?: string;
    tempat_lahir?: string | null;
    pekerjaan?: string | null;
    alamat_lengkap?: string;
    rt?: string | null;
    rw?: string | null;
    kode_pos?: string | null;
    golongan_darah_id?: number | null;
    agama_id?: number | null;
    pendidikan_id?: number | null;
    status_pernikahan_id?: number | null;
    provinsi_id?: number | null;
    kabupaten_kota_id?: number | null;
    kecamatan_id?: number | null;
    kelurahan_id?: number | null;
    tinggi_badan_cm?: number | null;
    berat_badan_kg?: number | null;
};

export async function fetchProfil() {
    return request<{ profile: PasienProfile }>('pasien/profil');
}

export async function updateProfil(input: UpdateProfilInput) {
    return request<{ profile: PasienProfile }>('pasien/profil', {
        method: 'PUT',
        json: input,
        retry: 0,
    });
}

export const profilQueryKey = ['v1', 'pasien', 'profil'] as const;

export function profilOptions() {
    return queryOptions({
        queryKey: profilQueryKey,
        queryFn: fetchProfil,
    });
}

export function updateProfilMutation() {
    return mutationOptions({
        mutationFn: updateProfil,
        /**
         * The server returns the whole row after the write, so the cache is replaced from
         * the response rather than refetched - one round trip instead of two.
         *
         * `/me` is invalidated rather than patched because `nama_lengkap` is published by
         * both resources, and the copy inside the cached `User` would otherwise keep
         * showing the old name on the dashboard and in the sidebar.
         */
        onSuccess: (result) => {
            queryClient.setQueryData(profilQueryKey, result);

            void queryClient.invalidateQueries({ queryKey: meQueryKey });
        },
    });
}
