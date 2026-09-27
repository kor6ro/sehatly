import type { PasienProfile } from '@/lib/api/types';
import {
    formatDecimal,
    formatJenisKelamin,
    formatNikMasked,
    formatTanggal,
    formatWaktu,
} from '@/lib/format';
import { cn } from '@/lib/utils';
import { Badge } from '@/components/ui/badge';
import { Separator } from '@/components/ui/separator';
import { ShieldAlert } from 'lucide-react';

/**
 * The read-only view of `GET /api/v1/pasien/profil`.
 *
 * ## The NIK is shown exactly as the server sent it, and there is no other behaviour
 *
 * `PasienResource` runs `nik` and `nomor_kk` through `App\Support\NikMasker` before the
 * value leaves PHP, keeping the first four and last four characters and replacing the rest
 * with a bullet. So what arrives here is already masked, is the same length as the stored
 * value, and is not a NIK. This component renders it verbatim: it does not trim it, does
 * not re-mask it, and offers no reveal. `formatNikMasked` exists only to decide what to
 * print when the column is `NULL`, so the two fields cannot disagree about that.
 *
 * A masked identifier is not editable, which is the server's rule and not a client choice:
 * `nik` and `nomor_kk` are absent from `UpdatePasienProfileRequest::rules()`, so a payload
 * carrying them is validated and then dropped.
 */
export function ProfileDetails({
    profile,
    className,
}: {
    profile: PasienProfile;
    className?: string;
}) {
    return (
        <div className={cn('flex flex-col gap-6', className)}>
            <section className="flex flex-col gap-3">
                <h2 className="text-base font-semibold">Identitas</h2>

                <dl className="grid gap-4 sm:grid-cols-2">
                    <Row label="Nama lengkap" value={profile.nama_lengkap ?? '-'} />

                    <Row label="Nomor rekam medis" value={profile.nomor_rm ?? '-'} />

                    <Row label="Jenis kelamin" value={formatJenisKelamin(profile.jenis_kelamin)} />

                    <Row label="Tanggal lahir" value={formatTanggal(profile.tanggal_lahir)} />

                    <Row label="Tempat lahir" value={profile.tempat_lahir ?? '-'} />

                    <Row
                        label="Pekerjaan"
                        value={profile.pekerjaan ?? '-'}
                    />
                </dl>
            </section>

            <Separator />

            <section className="flex flex-col gap-3">
                <h2 className="text-base font-semibold">Alamat</h2>

                <dl className="grid gap-4 sm:grid-cols-2">
                    <Row
                        label="Alamat lengkap"
                        value={profile.alamat_lengkap}
                        wide
                    />

                    <Row label="RT" value={profile.rt ?? '-'} />

                    <Row label="RW" value={profile.rw ?? '-'} />

                    <Row label="Kode pos" value={profile.kode_pos ?? '-'} />
                </dl>
            </section>

            <Separator />

            {/**
             * The NIK block is visually separated and carries an explicit note, because
             * two of these values look like data the user could edit and cannot be. A
             * greyed-out value with no explanation reads as a bug.
             */}
            <section className="flex flex-col gap-3">
                <h2 className="text-base font-semibold">Identitas nasional</h2>

                <dl className="grid gap-4 sm:grid-cols-2">
                    <Row label="NIK" value={formatNikMasked(profile.nik)} mono />

                    <Row
                        label="Nomor KK"
                        value={formatNikMasked(profile.nomor_kk)}
                        mono
                    />
                </dl>

                <p className="text-muted-foreground flex items-start gap-2 text-xs">
                    <ShieldAlert aria-hidden className="mt-0.5 size-3.5 shrink-0" />

                    <span>
                        NIK dan nomor KK ditampilkan dalam bentuk tersamar oleh server dan
                        tidak dapat diubah dari halaman ini. Nilai aslinya tidak pernah
                        dikirim ke peramban.
                    </span>
                </p>
            </section>

            <Separator />

            <section className="flex flex-col gap-3">
                <h2 className="text-base font-semibold">Kesehatan</h2>

                <dl className="grid gap-4 sm:grid-cols-2">
                    <Row
                        label="Tinggi badan"
                        value={`${formatDecimal(profile.tinggi_badan_cm)} cm`}
                    />

                    <Row
                        label="Berat badan"
                        value={`${formatDecimal(profile.berat_badan_kg, 2)} kg`}
                    />
                </dl>
            </section>

            <Separator />

            <section className="flex flex-col gap-3">
                <h2 className="text-base font-semibold">Riwayat</h2>

                <dl className="grid gap-4 sm:grid-cols-2">
                    <Row label="Dibuat" value={formatWaktu(profile.dibuat_at)} />

                    <Row label="Diubah" value={formatWaktu(profile.diubah_at)} />
                </dl>

                {profile.is_meninggal ? (
                    <Badge variant="secondary" className="w-fit">
                        Status downregulated:{' '}
                        {formatTanggal(profile.tanggal_meninggal)}
                    </Badge>
                ) : null}
            </section>
        </div>
    );
}

function Row({
    label,
    value,
    wide = false,
    mono = false,
}: {
    label: string;
    value: string;
    wide?: boolean;
    mono?: boolean;
}) {
    return (
        <div className={cn('flex flex-col gap-0.5', wide && 'sm:col-span-2')}>
            <dt className="text-muted-foreground text-xs">{label}</dt>

            <dd className={cn('text-sm font-medium', mono && 'font-mono')}>{value}</dd>
        </div>
    );
}

/**
 * The `master_*` reference ids, shown but not editable.
 *
 * ## Why this section has no inputs
 *
 * All eight are writable per `UpdatePasienProfileRequest`, and all eight need a
 * `master_*` row to pick from. Module 1's API exposes exactly **one** reference endpoint -
 * `GET /api/v1/master-spesialisasi` - so there is no way to populate
 * `master_golongan_darah`, `master_agama`, `master_pendidikan`, `master_status_pernikahan`
 * or the four wilayah tables without inventing a list the server never sent. Shipping a
 * hardcoded dropdown here would be a fabricated API response, which is the one thing this
 * client is not allowed to have.
 *
 * So the current values are displayed, the gap is stated, and the ids are left alone. The
 * four wilayah columns additionally carry a coherence check server-side
 * (`UpdatePasienProfileRequest::after()`), which a dropdown of ids could not help a user
 * satisfy anyway.
 */
export function ReferenceIdsNotice({ profile }: { profile: PasienProfile }) {
    const entries: Array<{ label: string; value: string }> = [
        { label: 'Golongan darah', value: nullableId(profile.golongan_darah_id) },
        { label: 'Agama', value: nullableId(profile.agama_id) },
        { label: 'Pendidikan', value: nullableId(profile.pendidikan_id) },
        {
            label: 'Status perkawinan',
            value: nullableId(profile.status_pernikahan_id),
        },
        { label: 'Provinsi', value: nullableId(profile.provinsi_id) },
        { label: 'Kabupaten/kota', value: nullableId(profile.kabupaten_kota_id) },
        { label: 'Kecamatan', value: nullableId(profile.kecamatan_id) },
        { label: 'Kelurahan', value: nullableId(profile.kelurahan_id) },
        { label: 'Rhesus', value: profile.rhesus ?? '-' },
    ];

    return (
        <section className="flex flex-col gap-3">
            <h2 className="text-base font-semibold">Data referensi</h2>

            <dl className="grid gap-4 sm:grid-cols-2">
                {entries.map((entry) => (
                    <Row key={entry.label} label={entry.label} value={entry.value} />
                ))}
            </dl>

            <p className="text-muted-foreground text-xs">
                Nilai ini tertaut ke tabel master. API modul 1 hanya menyediakan endpoint
                referensi untuk spesialisasi dokter, sehingga pilihan untuk kolom di atas
                belum dapat ditampilkan dan tidak diubah dari halaman ini.
            </p>
        </section>
    );
}

function nullableId(value: number | null): string {
    return value === null ? '-' : String(value);
}
