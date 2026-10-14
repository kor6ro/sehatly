import { useEffect, useState } from 'react';
import { Link } from 'react-router';
import { useMutation, useQuery } from '@tanstack/react-query';
import { AlarmClock, Check, ChevronRight, Minus, Monitor, Smartphone } from 'lucide-react';
import { ApiError } from '@/lib/http';
import { useOnlineStatus } from '@/hooks/use-online-status';
import { formatWaktuZona, labelZona } from '@/lib/waktu';
import { labelPerangkat, labelPlatform, perangkatAktif } from '@/lib/perangkat';
import { devicesOptions } from '@/lib/api/devices';
import type { UserDevice } from '@/lib/api/types';
import {
    LABEL_JAM_TENANG_MODE,
    LABEL_TIPE_PREFERENSI,
    opsiJamTenang,
    simpanPreferensiNotifikasiMutation,
    TIPE_PREFERENSI,
    type JamTenangMode,
    type PreferensiNotifikasi,
    type TipePreferensi,
} from '@/lib/api/notifikasi-preferensi';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { SelectItem } from '@/components/ui/select';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Field, FieldSelect, FormErrorSummary } from '@/components/form/field';
import { SkeletonRows } from '@/components/states/loading-state';
import { ErrorState } from '@/components/states/error-state';

/**
 * `/profil/notifikasi`'s body: the channel matrix, quiet hours, device list and save.
 *
 * ## One writable column, and the other one is explained rather than faked
 *
 * `preferensi_notifikasi_tipe` stores exactly one switch, `push_aktif`. In-app delivery
 * is unconditional, so "Dalam aplikasi" is a checked, disabled checkbox with a shared
 * explanation - not a control the server would ignore, and not a missing column the user
 * has to guess about.
 *
 * ## The four produced types only
 *
 * The matrix renders Booking, Pembayaran, Resep and Konsultasi. `lab`/`promo`/`sistem`
 * are refused by the request's whitelist and have no producer, so they are not rendered
 * at all; a disabled fifth row would be an invented control.
 *
 * ## Save errors are inline, never a toast
 *
 * A 422 renders per field through {@link Field}'s `errors` and the count through
 * `FormErrorSummary`; only a non-validation failure gets the destructive `Alert`. The
 * success line is a persistent `role="status"`, because a preference change is a state
 * the user should be able to read back rather than a three-second toast.
 */
export function PreferensiNotifikasiForm({ awal }: { awal: PreferensiNotifikasi }) {
    const online = useOnlineStatus();
    const devices = useQuery(devicesOptions());
    const simpan = useMutation(simpanPreferensiNotifikasiMutation());

    const [jamAktif, setJamAktif] = useState(awal.jam_tenang_aktif);
    const [mode, setMode] = useState<JamTenangMode>(awal.jam_tenang_mode);
    const [mulai, setMulai] = useState(awal.jam_tenang_mulai);
    const [selesai, setSelesai] = useState(awal.jam_tenang_selesai);
    const [push, setPush] = useState<Record<TipePreferensi, boolean>>(awal.push);
    const [sukses, setSukses] = useState(false);
    const [galat, setGalat] = useState<unknown>(null);

    useEffect(() => {
        setJamAktif(awal.jam_tenang_aktif);
        setMode(awal.jam_tenang_mode);
        setMulai(awal.jam_tenang_mulai);
        setSelesai(awal.jam_tenang_selesai);
        setPush(awal.push);
    }, [awal]);

    function ubah(aksi: () => void): void {
        setSukses(false);
        setGalat(null);
        aksi();
    }

    async function kirim(): Promise<void> {
        if (!online) {
            return;
        }

        setSukses(false);
        setGalat(null);

        try {
            const hasil = await simpan.mutateAsync({
                jam_tenang_aktif: jamAktif,
                jam_tenang_mode: mode,
                jam_tenang_mulai: mulai,
                jam_tenang_selesai: selesai,
                push,
            });

            const baru = hasil.data.preferensi;

            setJamAktif(baru.jam_tenang_aktif);
            setMode(baru.jam_tenang_mode);
            setMulai(baru.jam_tenang_mulai);
            setSelesai(baru.jam_tenang_selesai);
            setPush(baru.push);
            setSukses(true);
        } catch (error) {
            setGalat(error);
        }
    }

    const opsi = opsiJamTenang();
    const opsiMulai = opsi.includes(mulai) ? opsi : [mulai, ...opsi];
    const opsiSelesai = opsi.includes(selesai) ? opsi : [selesai, ...opsi];
    const galatValidasi = galat instanceof ApiError && galat.isValidation;
    const galatPush = galatValidasi ? galat.fieldErrors('push') : [];

    return (
        <form
            data-slot="preferensi-form"
            className="flex max-w-3xl flex-col gap-8"
            noValidate
            onSubmit={(event) => {
                event.preventDefault();
                void kirim();
            }}
        >
            <section aria-labelledby="preferensi-jenis-judul" className="flex flex-col gap-3">
                <div className="flex flex-col gap-1">
                    <h2 id="preferensi-jenis-judul" className="text-base font-semibold">
                        Jenis notifikasi
                    </h2>

                    <p className="text-muted-foreground text-sm">
                        Pilih pembaruan yang dikirim ke perangkat Anda. Dalam aplikasi
                        selalu tersimpan di kotak masuk.
                    </p>
                </div>

                <table
                    data-slot="preferensi-jenis"
                    className="w-full border-collapse text-left"
                >
                    <caption className="sr-only">
                        Kanal per jenis notifikasi
                    </caption>

                    <thead>
                        <tr className="border-border border-b">
                            <th scope="col" className="py-2 pr-3 text-sm font-medium">
                                Jenis
                            </th>

                            <th scope="col" className="px-3 py-2 text-sm font-medium">
                                Dalam aplikasi
                            </th>

                            <th scope="col" className="py-2 pl-3 text-sm font-medium">
                                Push
                            </th>
                        </tr>
                    </thead>

                    <tbody className="divide-border divide-y">
                        {TIPE_PREFERENSI.map((tipe) => {
                            const label = LABEL_TIPE_PREFERENSI[tipe];
                            const idInapp = `kanal-inapp-${tipe}`;
                            const idPush = `kanal-push-${tipe}`;
                            const errorPush = galatValidasi
                                ? galat.fieldErrors(`push.${tipe}`)
                                : [];
                            const aktif = push[tipe];

                            return (
                                <tr key={tipe} data-slot="preferensi-baris" data-tipe={tipe}>
                                    <th
                                        scope="row"
                                        className="py-3 pr-3 text-base font-medium"
                                    >
                                        {label}
                                    </th>

                                    <td className="px-3 py-3">
                                        <div className="flex items-center gap-2">
                                            <Checkbox
                                                id={idInapp}
                                                data-slot="preferensi-inapp"
                                                data-tipe={tipe}
                                                checked
                                                disabled
                                                aria-label={`Dalam aplikasi untuk ${label} selalu aktif`}
                                                aria-describedby="kanal-inapp-alasan"
                                            />

                                            <span className="text-muted-foreground flex items-center gap-1.5 text-sm">
                                                <Check
                                                    aria-hidden
                                                    className="text-success size-4"
                                                />

                                                Selalu
                                            </span>
                                        </div>
                                    </td>

                                    <td className="py-3 pl-3">
                                        <div className="flex items-center gap-2">
                                            <Checkbox
                                                id={idPush}
                                                data-slot="preferensi-push"
                                                data-tipe={tipe}
                                                checked={aktif}
                                                aria-label={`Push untuk ${label}`}
                                                onCheckedChange={(nilai) => {
                                                    ubah(() => {
                                                        setPush({
                                                            ...push,
                                                            [tipe]: nilai === true,
                                                        });
                                                    });
                                                }}
                                            />

                                            <span className="text-muted-foreground flex items-center gap-1.5 text-sm">
                                                {aktif ? (
                                                    <Check
                                                        aria-hidden
                                                        className="text-success size-4"
                                                    />
                                                ) : (
                                                    <Minus aria-hidden className="size-4" />
                                                )}

                                                {aktif ? 'Aktif' : 'Nonaktif'}
                                            </span>
                                        </div>

                                        {errorPush.length === 0 ? null : (
                                            <p className="text-destructive mt-1 text-xs">
                                                {errorPush.join(' ')}
                                            </p>
                                        )}
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>

                <p
                    id="kanal-inapp-alasan"
                    className="text-muted-foreground text-xs"
                >
                    Dalam aplikasi: selalu tersimpan di kotak masuk; tidak dapat dimatikan.
                </p>

                {galatPush.length === 0 ? null : (
                    <p role="alert" className="text-destructive text-sm">
                        {galatPush.join(' ')}
                    </p>
                )}
            </section>

            <section aria-labelledby="jam-tenang-judul" className="flex flex-col gap-4">
                <div className="flex flex-col gap-1">
                    <h2 id="jam-tenang-judul" className="text-base font-semibold">
                        Jam tenang
                    </h2>

                    <p className="text-muted-foreground text-sm">
                        Jam tenang memakai zona {labelZona(awal.zona_waktu)} (
                        {awal.zona_waktu}).
                    </p>
                </div>

                <div className="flex items-center gap-2">
                    <Checkbox
                        id="jam-tenang-aktif"
                        data-slot="jam-tenang-aktif"
                        checked={jamAktif}
                        onCheckedChange={(nilai) => {
                            ubah(() => {
                                setJamAktif(nilai === true);
                            });
                        }}
                    />

                    <Label htmlFor="jam-tenang-aktif">Aktifkan jam tenang</Label>
                </div>

                <ToggleGroup
                    type="single"
                    variant="outline"
                    aria-label="Mode jam tenang"
                    className="flex-wrap"
                    value={mode}
                    onValueChange={(nilai) => {
                        if (nilai === '' || nilai === null) {
                            return;
                        }

                        ubah(() => {
                            setMode(nilai as JamTenangMode);
                        });
                    }}
                >
                    {(Object.keys(LABEL_JAM_TENANG_MODE) as JamTenangMode[]).map(
                        (nilai) => (
                            <ToggleGroupItem
                                key={nilai}
                                data-slot="jam-tenang-mode"
                                value={nilai}
                                className="min-h-11 px-3"
                            >
                                {LABEL_JAM_TENANG_MODE[nilai]}
                            </ToggleGroupItem>
                        ),
                    )}
                </ToggleGroup>

                <div className="grid gap-4 sm:grid-cols-2">
                    <Field
                        label="Jam mulai"
                        slot="jam-tenang-mulai"
                        errors={galatValidasi ? galat.fieldErrors('jam_tenang_mulai') : []}
                    >
                        <FieldSelect
                            value={mulai}
                            className="min-h-11 w-full"
                            onValueChange={(nilai) => {
                                ubah(() => {
                                    setMulai(nilai);
                                });
                            }}
                        >
                            {opsiMulai.map((jam) => (
                                <SelectItem key={jam} value={jam}>
                                    {jam.replace(':', '.')}
                                </SelectItem>
                            ))}
                        </FieldSelect>
                    </Field>

                    <Field
                        label="Jam selesai"
                        slot="jam-tenang-selesai"
                        errors={galatValidasi ? galat.fieldErrors('jam_tenang_selesai') : []}
                    >
                        <FieldSelect
                            value={selesai}
                            className="min-h-11 w-full"
                            onValueChange={(nilai) => {
                                ubah(() => {
                                    setSelesai(nilai);
                                });
                            }}
                        >
                            {opsiSelesai.map((jam) => (
                                <SelectItem key={jam} value={jam}>
                                    {jam.replace(':', '.')}
                                </SelectItem>
                            ))}
                        </FieldSelect>
                    </Field>
                </div>

                <p className="text-muted-foreground text-sm">
                    Saat jam tenang, push ditahan. Notifikasi tetap masuk ke kotak masuk
                    aplikasi.
                </p>

                <p className="text-muted-foreground text-sm">
                    Setelah jam tenang berakhir, pembaruan dikirim sebagai satu ringkasan.
                </p>
            </section>

            <section aria-labelledby="perangkat-judul" className="flex flex-col gap-3">
                <div className="flex flex-col gap-1">
                    <h2 id="perangkat-judul" className="text-base font-semibold">
                        Perangkat &amp; pengiriman
                    </h2>

                    <p className="text-muted-foreground text-sm">
                        Perangkat yang masuk dengan akun ini beserta versi aplikasinya.
                    </p>
                </div>

                {devices.isPending ? (
                    <div data-slot="preferensi-perangkat-loading">
                        <SkeletonRows rows={2} />
                    </div>
                ) : devices.isError ? (
                    <ErrorState
                        error={devices.error}
                        title="Gagal memuat perangkat."
                        onRetry={() => {
                            void devices.refetch();
                        }}
                    />
                ) : (
                    <PerangkatList
                        devices={devices.data.data.devices.filter(perangkatAktif)}
                    />
                )}

                <p className="text-muted-foreground text-xs">
                    Status pengiriman push per perangkat belum tersedia; halaman ini hanya
                    menampilkan perangkat yang terdaftar.
                </p>
            </section>

            <section aria-labelledby="pengingat-tautan-judul" className="flex flex-col gap-2">
                <h2 id="pengingat-tautan-judul" className="text-base font-semibold">
                    Pengingat saya
                </h2>

                <p className="text-muted-foreground text-sm">
                    Atur pengingat obat dan janji temu, termasuk waktunya di zona Anda.
                </p>

                <Button
                    asChild
                    variant="outline"
                    className="min-h-11 w-fit"
                    data-slot="preferensi-pengingat-link"
                >
                    <Link to="/pengingat">
                        <AlarmClock aria-hidden />

                        Buka pengingat saya

                        <ChevronRight aria-hidden />
                    </Link>
                </Button>
            </section>

            <div className="flex flex-col gap-3">
                <FormErrorSummary error={galat} />

                {galat !== null && !galatValidasi ? (
                    <Alert variant="destructive" role="alert" data-slot="preferensi-galat">
                        <AlertTitle>Gagal menyimpan preferensi.</AlertTitle>

                        <AlertDescription>
                            <p>
                                {galat instanceof ApiError
                                    ? galat.message
                                    : 'Terjadi kesalahan yang tidak diketahui.'}
                            </p>
                        </AlertDescription>
                    </Alert>
                ) : null}

                {sukses ? (
                    <p
                        role="status"
                        data-slot="preferensi-sukses"
                        className="text-foreground flex items-center gap-2 text-sm"
                    >
                        <Check aria-hidden className="text-success size-4 shrink-0" />

                        Preferensi notifikasi tersimpan.
                    </p>
                ) : null}

                {!online ? (
                    <p
                        data-slot="preferensi-offline-alasan"
                        className="text-muted-foreground text-sm"
                    >
                        Menyimpan preferensi dinonaktifkan sampai koneksi kembali.
                    </p>
                ) : null}

                <Button
                    type="submit"
                    data-slot="preferensi-simpan"
                    className="min-h-11 w-fit"
                    disabled={simpan.isPending}
                    aria-disabled={!online || simpan.isPending ? true : undefined}
                >
                    {simpan.isPending ? 'Menyimpan...' : 'Simpan preferensi'}
                </Button>
            </div>
        </form>
    );
}

function PerangkatList({ devices }: { devices: UserDevice[] }) {
    if (devices.length === 0) {
        return (
            <p className="text-muted-foreground text-sm">
                Belum ada perangkat aktif. Perangkat yang masuk akan muncul di sini.
            </p>
        );
    }

    return (
        <ul className="flex flex-col gap-2" data-slot="preferensi-perangkat">
            {devices.map((device) => (
                <li
                    key={device.device_id}
                    data-slot="preferensi-perangkat-item"
                    className="border-border flex items-start gap-3 rounded-lg border p-3"
                >
                    {device.platform === 'web' ? (
                        <Monitor
                            aria-hidden
                            className="text-muted-foreground mt-0.5 size-5 shrink-0"
                        />
                    ) : (
                        <Smartphone
                            aria-hidden
                            className="text-muted-foreground mt-0.5 size-5 shrink-0"
                        />
                    )}

                    <div className="flex flex-col gap-1">
                        <span className="text-base font-medium">
                            {labelPerangkat(device)}
                        </span>

                        <span className="text-muted-foreground text-sm">
                            {labelPlatform(device.platform)} •{' '}
                            {device.aktif ? 'Aktif' : 'Nonaktif'} • Terakhir aktif{' '}
                            {formatWaktuZona(device.last_active_at)}
                        </span>
                    </div>
                </li>
            ))}
        </ul>
    );
}
