import { useEffect, useMemo, useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { AlertCircle, CalendarPlus, Plus } from 'lucide-react';
import { toast } from 'sonner';
import {
    adminJadwalOptions,
    adminLiburOptions,
    hapusJadwal,
    hapusLibur,
    simpanJadwal,
    simpanLibur,
    ubahJadwal,
    type AdminJadwal,
    type StoreJadwalBody,
    type TipeLayananJadwalAdmin,
} from '@/lib/api/admin';
import { ApiError } from '@/lib/http';
import { useOnlineStatus } from '@/hooks/use-online-status';
import {
    gantiJadwalCache,
    hapusJadwalCache,
    hapusLiburCache,
    tambahJadwalCache,
    tambahLiburCache,
} from '@/features/admin/cache';
import { pesanField, pesanRingkas } from '@/features/admin/pesan';
import { labelHari, labelTipeLayanan, tanggalSingkat } from '@/features/admin/format-admin';
import { formatRentangJamZona } from '@/lib/waktu';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Field, FieldInput, FieldSelect, FieldTextarea } from '@/components/form/field';
import { SelectItem } from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import {
    Alert,
    AlertDescription,
    AlertTitle,
} from '@/components/ui/alert';
import { AdminErrorState } from '@/features/admin/admin-gate';
import { EmptyState } from '@/components/states/empty-state';
import { SkeletonRows } from '@/components/states/loading-state';

const HARI: ReadonlyArray<number> = [0, 1, 2, 3, 4, 5, 6];

const TIPE_LAYANAN: ReadonlyArray<TipeLayananJadwalAdmin> = [
    'online',
    'klinik',
    'home_visit',
];

function hariIniYmd(): string {
    const sekarang = new Date();

    return [
        String(sekarang.getFullYear()).padStart(4, '0'),
        String(sekarang.getMonth() + 1).padStart(2, '0'),
        String(sekarang.getDate()).padStart(2, '0'),
    ].join('-');
}

function menitDariJam(jam: string): number | null {
    const cocok = /^(\d{2}):(\d{2})(?::\d{2})?$/.exec(jam);

    if (cocok === null) {
        return null;
    }

    const jamAngka = Number(cocok[1]);
    const menitAngka = Number(cocok[2]);

    if (jamAngka > 23 || menitAngka > 59) {
        return null;
    }

    return jamAngka * 60 + menitAngka;
}

function hitungSlot(mulai: string, selesai: string, durasi: number): number {
    const awal = menitDariJam(mulai);
    const akhir = menitDariJam(selesai);

    if (awal === null || akhir === null || durasi < 1 || akhir <= awal) {
        return 0;
    }

    return Math.floor((akhir - awal) / durasi);
}

/**
 * The weekly `dokter_jadwal` windows of one doctor.
 *
 * ## Drafts are first-class because publishing is a separate decision
 *
 * The backend stores a window as a draft (`status_aktif = false`) and refuses
 * an overlap only between ACTIVE windows, so two drafts may coexist while a
 * publish catches the conflict with a 422 on `errors.hari`. That is why the
 * create form offers "Simpan sebagai draf" and "Terbitkan" as two verbs, and why
 * a draft row has a "Terbitkan" action instead of the create form hiding the
 * distinction.
 *
 * ## A booking-bearing window is deactivated, not deleted
 *
 * `booking.jadwal_id` is a RESTRICT foreign key: any booking - even a finished
 * one - makes the row undeletable, and the server says so with a 422 naming the
 * count. The UI offers "Hapus" only at `booking_aktif === 0`; every other row
 * offers "Nonaktifkan", and a 422 that still arrives (a concurrent booking) is
 * rendered with the server's own count and a "Nonaktifkan saja" action.
 */
export function AdminJadwalSection({ dokterId }: { dokterId: number }) {
    const online = useOnlineStatus();
    const queryClient = useQueryClient();

    const jadwal = useQuery(adminJadwalOptions(dokterId));
    const baris = jadwal.data?.data.jadwal ?? [];

    const [sheetBuka, setSheetBuka] = useState(false);
    const [edit, setEdit] = useState<AdminJadwal | null>(null);
    const [sukses, setSukses] = useState<string | null>(null);
    const [errorForm, setErrorForm] = useState<Record<string, string[]> | null>(null);
    const [hapusGagal, setHapusGagal] = useState<{ id: number; pesan: string } | null>(
        null,
    );
    const [terbitGagal, setTerbitGagal] = useState<string | null>(null);

    const [hari, setHari] = useState<number[]>([]);
    const [jamMulai, setJamMulai] = useState('08:00');
    const [jamSelesai, setJamSelesai] = useState('12:00');
    const [durasi, setDurasi] = useState('15');
    const [batasiKuota, setBatasiKuota] = useState(false);
    const [kuota, setKuota] = useState('');
    const [tipeLayanan, setTipeLayanan] = useState<TipeLayananJadwalAdmin>('klinik');
    const [berlakuMulai, setBerlakuMulai] = useState(hariIniYmd);
    const [berlakuSampai, setBerlakuSampai] = useState('');

    const onlineSebelumnya = useRef(online);

    useEffect(() => {
        if (!onlineSebelumnya.current && online) {
            void jadwal.refetch();
        }

        onlineSebelumnya.current = online;
    }, [online, jadwal.refetch]);

    const pratinjau = useMemo(() => {
        const durasiAngka = Number(durasi);
        const jumlah = hitungSlot(jamMulai, jamSelesai, durasiAngka);

        return hari.map((satuHari) => ({
            hari: satuHari,
            label: labelHari(satuHari),
            jumlah,
        }));
    }, [hari, jamMulai, jamSelesai, durasi]);

    const bukaTambah = (): void => {
        setEdit(null);
        setSukses(null);
        setErrorForm(null);
        setHari([]);
        setJamMulai('08:00');
        setJamSelesai('12:00');
        setDurasi('15');
        setBatasiKuota(false);
        setKuota('');
        setTipeLayanan('klinik');
        setBerlakuMulai(hariIniYmd());
        setBerlakuSampai('');
        setSheetBuka(true);
    };

    const bukaUbah = (row: AdminJadwal): void => {
        setEdit(row);
        setSukses(null);
        setErrorForm(null);
        setHari([row.hari]);
        setJamMulai(row.jam_mulai.slice(0, 5));
        setJamSelesai(row.jam_selesai.slice(0, 5));
        setDurasi(String(row.durasi_slot_menit));
        setBatasiKuota(row.kuota_per_sesi !== null);
        setKuota(row.kuota_per_sesi === null ? '' : String(row.kuota_per_sesi));
        setTipeLayanan(row.tipe_layanan);
        setBerlakuMulai(row.berlaku_mulai?.slice(0, 10) ?? hariIniYmd());
        setBerlakuSampai(row.berlaku_sampai?.slice(0, 10) ?? '');
        setSheetBuka(true);
    };

    const validasi = (): boolean => {
        const errors: Record<string, string[]> = {};

        if (hari.length === 0) {
            errors.hari = ['Pilih minimal satu hari.'];
        }

        if (durasi === '' || Number(durasi) < 1) {
            errors.durasi_slot_menit = ['Durasi slot minimal 1 menit.'];
        }

        if (berlakuMulai === '') {
            errors.berlaku_mulai = ['Tanggal berlaku mulai wajib diisi.'];
        }

        if (berlakuSampai !== '' && berlakuSampai < berlakuMulai) {
            errors.berlaku_sampai = [
                'Tanggal berlaku sampai tidak boleh lebih awal dari berlaku mulai.',
            ];
        }

        if (batasiKuota && (kuota === '' || Number(kuota) < 0)) {
            errors.kuota_per_sesi = ['Isi jumlah kuota atau matikan batas kuota.'];
        }

        setErrorForm(Object.keys(errors).length === 0 ? null : errors);

        return Object.keys(errors).length === 0;
    };

    const bodyDariForm = (): StoreJadwalBody => ({
        hari: [...hari].sort((a, b) => a - b),
        tipe_layanan: tipeLayanan,
        jam_mulai: jamMulai,
        jam_selesai: jamSelesai,
        durasi_slot_menit: Number(durasi),
        ...(batasiKuota ? { kuota_per_sesi: Number(kuota) } : {}),
        berlaku_mulai: berlakuMulai,
        ...(berlakuSampai === '' ? {} : { berlaku_sampai: berlakuSampai }),
        status_aktif: false,
    });

    const buat = useMutation({
        mutationFn: (input: { body: StoreJadwalBody; terbit: boolean }) =>
            simpanJadwal(dokterId, {
                ...input.body,
                status_aktif: input.terbit,
            }),
        onSuccess: (hasil, input) => {
            tambahJadwalCache(queryClient, dokterId, hasil.data.jadwal);

            const status = input.terbit ? 'Terbit' : 'Draf';

            setSukses(
                `${hasil.data.jadwal.length} jadwal disimpan sebagai ${status}.`,
            );
            setErrorForm(null);
            toast.success('Jadwal disimpan.');
        },
        onError: (error) => {
            if (error instanceof ApiError && error.isValidation) {
                setErrorForm(error.errors);

                return;
            }

            toast.error(pesanRingkas(error) ?? 'Jadwal gagal disimpan.');
        },
    });

    const ubah = useMutation({
        mutationFn: (body: StoreJadwalBody) => {
            if (edit === null) {
                throw new Error('Tidak ada jadwal yang disunting.');
            }

            const { hari: hariPilihan, ...sisa } = body;

            return ubahJadwal(edit.id, {
                ...sisa,
                hari: hariPilihan[0] ?? edit.hari,
            });
        },
        onSuccess: (hasil) => {
            gantiJadwalCache(queryClient, dokterId, hasil.data.jadwal);
            toast.success('Jadwal disimpan.');
            setSheetBuka(false);
            setEdit(null);
            setErrorForm(null);
        },
        onError: (error) => {
            if (error instanceof ApiError && error.isValidation) {
                setErrorForm(error.errors);

                return;
            }

            toast.error(pesanRingkas(error) ?? 'Jadwal gagal disimpan.');
        },
    });

    const hapus = useMutation({
        mutationFn: (id: number) => hapusJadwal(id),
        onSuccess: (_hasil, id) => {
            hapusJadwalCache(queryClient, dokterId, id);
            setHapusGagal(null);
            toast.success('Jadwal dihapus.');
        },
        onError: (error, id) => {
            setHapusGagal({
                id,
                pesan:
                    pesanField(error, 'jadwal') ?? 'Jadwal tidak dapat dihapus.',
            });
        },
    });

    const nonaktif = useMutation({
        mutationFn: (id: number) => ubahJadwal(id, { status_aktif: false }),
        onSuccess: (hasil) => {
            gantiJadwalCache(queryClient, dokterId, hasil.data.jadwal);
            setHapusGagal(null);
            toast.success('Jadwal dinonaktifkan.');
        },
        onError: (error) => {
            toast.error(pesanRingkas(error) ?? 'Jadwal gagal dinonaktifkan.');
        },
    });

    const terbit = useMutation({
        mutationFn: (id: number) => ubahJadwal(id, { status_aktif: true }),
        onSuccess: (hasil) => {
            gantiJadwalCache(queryClient, dokterId, hasil.data.jadwal);
            setTerbitGagal(null);
            toast.success('Jadwal diterbitkan.');
        },
        onError: (error) => {
            if (error instanceof ApiError && error.isValidation) {
                setTerbitGagal(
                    pesanField(error, 'hari') ?? 'Jadwal tidak dapat diterbitkan.',
                );

                return;
            }

            toast.error(pesanRingkas(error) ?? 'Jadwal gagal diterbitkan.');
        },
    });

    const sedangSimpan = buat.isPending || ubah.isPending;

    return (
        <section className="flex flex-col gap-4" aria-label="Jadwal rutin dokter">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="text-lg font-semibold">Jadwal rutin</h2>

                <div data-testid="admin-aksi">
                    <Button
                        type="button"
                        className="h-11"
                        data-testid="admin-tulis"
                        disabled={!online}
                        onClick={bukaTambah}
                    >
                        <Plus aria-hidden />
                        Tambah jadwal
                    </Button>
                </div>
            </div>

            {terbitGagal === null ? null : (
                <Alert variant="destructive" role="alert" data-slot="admin-terbit-gagal">
                    <AlertCircle aria-hidden />

                    <AlertTitle>Jadwal tidak dapat diterbitkan</AlertTitle>

                    <AlertDescription>{terbitGagal}</AlertDescription>
                </Alert>
            )}

            {hapusGagal === null ? null : (
                <Alert variant="destructive" role="alert" data-slot="admin-hapus-gagal">
                    <AlertCircle aria-hidden />

                    <AlertTitle>Jadwal tidak dapat dihapus</AlertTitle>

                    <AlertDescription className="flex flex-col items-start gap-2">
                        <p>{hapusGagal.pesan}</p>

                        <div data-testid="admin-aksi">
                            <Button
                                type="button"
                                variant="outline"
                                className="h-11"
                                data-testid="admin-tulis"
                                disabled={!online || nonaktif.isPending}
                                onClick={() => {
                                    nonaktif.mutate(hapusGagal.id);
                                }}
                            >
                                Nonaktifkan saja
                            </Button>
                        </div>
                    </AlertDescription>
                </Alert>
            )}

            {jadwal.isPending ? (
                <SkeletonRows rows={3} />
            ) : jadwal.isError ? (
                <AdminErrorState
                    title="Gagal memuat jadwal dokter."
                    error={jadwal.error}
                    onRetry={() => {
                        void jadwal.refetch();
                    }}
                />
            ) : baris.length === 0 ? (
                <EmptyState
                    title="Belum ada jadwal untuk dokter ini."
                    description="Tambahkan jadwal rutin mingguan agar pasien dapat memesan slot."
                    action={
                        <Button
                            type="button"
                            variant="outline"
                            className="h-11"
                            disabled={!online}
                            data-testid="admin-tulis"
                            onClick={bukaTambah}
                        >
                            <CalendarPlus aria-hidden />
                            Tambah jadwal
                        </Button>
                    }
                />
            ) : (
                <ul role="list" className="flex flex-col gap-3">
                    {baris.map((row) => {
                        const jumlah = hitungSlot(
                            row.jam_mulai,
                            row.jam_selesai,
                            row.durasi_slot_menit,
                        );

                        return (
                            <li
                                key={row.id}
                                data-slot="admin-jadwal-row"
                                data-jadwal-id={row.id}
                                data-booking-aktif={row.booking_aktif}
                                className="border-border flex flex-col gap-3 rounded-lg border p-4 md:flex-row md:items-start md:justify-between"
                            >
                                <div className="flex min-w-0 flex-col gap-2">
                                    <p className="text-base font-semibold">
                                        {row.hari_label ?? labelHari(row.hari)}{' '}
                                        <span className="tabular-nums">
                                            {formatRentangJamZona(
                                                row.jam_mulai,
                                                row.jam_selesai,
                                                row.berlaku_mulai ?? hariIniYmd(),
                                            )}
                                        </span>
                                    </p>

                                    <p className="text-base tabular-nums">
                                        {row.durasi_slot_menit} menit · {jumlah} slot
                                        {row.kuota_per_sesi === null
                                            ? ''
                                            : ` · kuota ${row.kuota_per_sesi} pasien`}
                                        {' · '}
                                        {labelTipeLayanan(row.tipe_layanan)}
                                    </p>

                                    <p className="text-muted-foreground text-sm tabular-nums">
                                        Berlaku {tanggalSingkat(row.berlaku_mulai)} s.d.{' '}
                                        {row.berlaku_sampai === null
                                            ? 'tanpa batas'
                                            : tanggalSingkat(row.berlaku_sampai)}
                                    </p>

                                    <div className="flex flex-wrap items-center gap-2">
                                        <Badge
                                            variant={row.status_aktif ? 'default' : 'outline'}
                                            data-slot="admin-jadwal-status"
                                            data-status={row.status_aktif ? 'terbit' : 'draf'}
                                        >
                                            {row.status_aktif ? 'Terbit' : 'Draf'}
                                        </Badge>

                                        {row.booking_aktif > 0 ? (
                                            <span className="text-sm tabular-nums">
                                                {row.booking_aktif} booking aktif — booking
                                                yang ada tetap berjalan bila
                                                dinonaktifkan.
                                            </span>
                                        ) : null}
                                    </div>
                                </div>

                                <div
                                    data-testid="admin-aksi"
                                    className="flex flex-wrap gap-2 md:justify-end"
                                >
                                    <Button
                                        type="button"
                                        variant="outline"
                                        className="h-11"
                                        data-testid="admin-tulis"
                                        disabled={!online}
                                        onClick={() => {
                                            bukaUbah(row);
                                        }}
                                    >
                                        Ubah
                                    </Button>

                                    {row.status_aktif ? (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            className="h-11"
                                            data-testid="admin-tulis"
                                            disabled={!online || nonaktif.isPending}
                                            onClick={() => {
                                                nonaktif.mutate(row.id);
                                            }}
                                        >
                                            Nonaktifkan
                                        </Button>
                                    ) : (
                                        <Button
                                            type="button"
                                            className="h-11"
                                            data-testid="admin-tulis"
                                            disabled={!online || terbit.isPending}
                                            onClick={() => {
                                                terbit.mutate(row.id);
                                            }}
                                        >
                                            Terbitkan
                                        </Button>
                                    )}

                                    {row.booking_aktif === 0 ? (
                                        <Button
                                            type="button"
                                            variant="destructive"
                                            className="h-11"
                                            data-testid="admin-tulis"
                                            disabled={!online || hapus.isPending}
                                            onClick={() => {
                                                hapus.mutate(row.id);
                                            }}
                                        >
                                            Hapus
                                        </Button>
                                    ) : null}
                                </div>
                            </li>
                        );
                    })}
                </ul>
            )}

            <Sheet
                open={sheetBuka}
                onOpenChange={(buka) => {
                    setSheetBuka(buka);

                    if (!buka) {
                        setEdit(null);
                        setSukses(null);
                        setErrorForm(null);
                    }
                }}
            >
                <SheetContent
                    side="right"
                    className="flex w-full flex-col overflow-y-auto sm:max-w-xl"
                >
                    <SheetHeader>
                        <SheetTitle>
                            {edit === null ? 'Tambah jadwal' : 'Ubah jadwal'}
                        </SheetTitle>

                        <SheetDescription>
                            Jadwal rutin berlaku setiap minggu pada hari yang dipilih.
                            Semua jam adalah WIB.
                        </SheetDescription>
                    </SheetHeader>

                    {sukses === null ? (
                        <form
                            noValidate
                            className="flex flex-col gap-4 px-4"
                            onSubmit={(event) => {
                                event.preventDefault();
                            }}
                        >
                            {errorForm?.hari === undefined ? null : (
                                <Alert
                                    variant="destructive"
                                    role="alert"
                                    data-slot="admin-jadwal-errors"
                                >
                                    <AlertCircle aria-hidden />

                                    <AlertTitle>Jadwal bertumpuk</AlertTitle>

                                    <AlertDescription>
                                        <ul className="flex flex-col gap-1">
                                            {errorForm.hari.map((pesan) => (
                                                <li key={pesan}>{pesan}</li>
                                            ))}
                                        </ul>
                                    </AlertDescription>
                                </Alert>
                            )}

                            <fieldset className="flex flex-col gap-2">
                                <legend className="text-sm font-medium">
                                    Hari <span aria-hidden>*</span>
                                </legend>

                                <div className="flex flex-wrap gap-2">
                                    {HARI.map((satuHari) => (
                                        <label
                                            key={satuHari}
                                            className="border-input flex min-h-11 items-center gap-2 rounded-md border px-3 text-sm"
                                        >
                                            <Checkbox
                                                aria-label={labelHari(satuHari)}
                                                checked={hari.includes(satuHari)}
                                                onCheckedChange={(nilai) => {
                                                    setHari((lama) => {
                                                        if (edit !== null) {
                                                            return nilai === true
                                                                ? [satuHari]
                                                                : [];
                                                        }

                                                        return nilai === true
                                                            ? [...lama, satuHari].sort(
                                                                  (a, b) => a - b,
                                                              )
                                                            : lama.filter(
                                                                  (item) =>
                                                                      item !== satuHari,
                                                              );
                                                    });
                                                }}
                                            />

                                            {labelHari(satuHari)}
                                        </label>
                                    ))}
                                </div>

                                {errorForm?.hari === undefined ? null : (
                                    <p className="text-destructive text-xs">
                                        {errorForm.hari.join(' ')}
                                    </p>
                                )}
                            </fieldset>

                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <Field
                                    label="Jam mulai"
                                    required
                                    errors={errorForm?.jam_mulai ?? []}
                                >
                                    <FieldInput
                                        type="time"
                                        value={jamMulai}
                                        onChange={(event) => {
                                            setJamMulai(event.target.value);
                                        }}
                                    />
                                </Field>

                                <Field
                                    label="Jam selesai"
                                    required
                                    errors={errorForm?.jam_selesai ?? []}
                                >
                                    <FieldInput
                                        type="time"
                                        value={jamSelesai}
                                        onChange={(event) => {
                                            setJamSelesai(event.target.value);
                                        }}
                                    />
                                </Field>
                            </div>

                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <Field
                                    label="Durasi per slot (menit)"
                                    required
                                    errors={errorForm?.durasi_slot_menit ?? []}
                                >
                                    <FieldInput
                                        type="number"
                                        min={1}
                                        value={durasi}
                                        onChange={(event) => {
                                            setDurasi(event.target.value);
                                        }}
                                    />
                                </Field>

                                <Field
                                    label="Kuota pasien (opsional)"
                                    errors={errorForm?.kuota_per_sesi ?? []}
                                    hint={
                                        batasiKuota
                                            ? 'Kuota membatasi jumlah pasien per sesi.'
                                            : 'Tanpa batas kuota.'
                                    }
                                >
                                    <div className="flex flex-col gap-2">
                                        <label className="flex min-h-11 items-center gap-2 text-sm">
                                            <Checkbox
                                                aria-label="Batasi kuota per sesi"
                                                checked={batasiKuota}
                                                onCheckedChange={(nilai) => {
                                                    setBatasiKuota(nilai === true);
                                                }}
                                            />

                                            Batasi kuota
                                        </label>

                                        {batasiKuota ? (
                                            <FieldInput
                                                type="number"
                                                min={0}
                                                value={kuota}
                                                onChange={(event) => {
                                                    setKuota(event.target.value);
                                                }}
                                            />
                                        ) : null}
                                    </div>
                                </Field>
                            </div>

                            <Field label="Tipe layanan" required>
                                <FieldSelect
                                    value={tipeLayanan}
                                    onValueChange={(nilai) => {
                                        setTipeLayanan(
                                            nilai as TipeLayananJadwalAdmin,
                                        );
                                    }}
                                >
                                    {TIPE_LAYANAN.map((tipe) => (
                                        <SelectItem key={tipe} value={tipe}>
                                            {labelTipeLayanan(tipe)}
                                        </SelectItem>
                                    ))}
                                </FieldSelect>
                            </Field>

                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <Field
                                    label="Berlaku mulai"
                                    required
                                    errors={errorForm?.berlaku_mulai ?? []}
                                >
                                    <FieldInput
                                        type="date"
                                        value={berlakuMulai}
                                        onChange={(event) => {
                                            setBerlakuMulai(event.target.value);
                                        }}
                                    />
                                </Field>

                                <Field
                                    label="Berlaku sampai (opsional)"
                                    errors={errorForm?.berlaku_sampai ?? []}
                                >
                                    <FieldInput
                                        type="date"
                                        value={berlakuSampai}
                                        onChange={(event) => {
                                            setBerlakuSampai(event.target.value);
                                        }}
                                    />
                                </Field>
                            </div>

                            <div
                                role="status"
                                data-slot="admin-pratinjau"
                                className="bg-muted/40 rounded-md border p-3 text-sm"
                            >
                                <p className="font-medium">
                                    Pratinjau:{' '}
                                    {pratinjau.length === 0
                                        ? 'pilih hari untuk melihat jumlah slot.'
                                        : pratinjau
                                              .map(
                                                  (item) =>
                                                      `${item.label} ${item.jumlah} slot`,
                                              )
                                              .join(' · ')}
                                </p>

                                <p className="text-muted-foreground tabular-nums">
                                    {formatRentangJamZona(
                                        jamMulai,
                                        jamSelesai,
                                        berlakuMulai === ''
                                            ? hariIniYmd()
                                            : berlakuMulai,
                                    )}
                                    {' · '}
                                    {durasi === '' ? 0 : Number(durasi)} menit per slot
                                </p>
                            </div>

                            <SheetFooter className="flex flex-wrap gap-2 px-0">
                                <div data-testid="admin-aksi" className="flex flex-wrap gap-2">
                                    {edit === null ? (
                                        <>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                className="h-11"
                                                data-testid="admin-tulis"
                                                disabled={!online || sedangSimpan}
                                                onClick={() => {
                                                    if (validasi()) {
                                                        buat.mutate({
                                                            body: bodyDariForm(),
                                                            terbit: false,
                                                        });
                                                    }
                                                }}
                                            >
                                                Simpan sebagai draf
                                            </Button>

                                            <Button
                                                type="button"
                                                className="h-11"
                                                data-testid="admin-tulis"
                                                disabled={!online || sedangSimpan}
                                                onClick={() => {
                                                    if (validasi()) {
                                                        buat.mutate({
                                                            body: bodyDariForm(),
                                                            terbit: true,
                                                        });
                                                    }
                                                }}
                                            >
                                                Terbitkan
                                            </Button>
                                        </>
                                    ) : (
                                        <Button
                                            type="button"
                                            className="h-11"
                                            data-testid="admin-tulis"
                                            disabled={!online || sedangSimpan}
                                            onClick={() => {
                                                if (validasi()) {
                                                    ubah.mutate(bodyDariForm());
                                                }
                                            }}
                                        >
                                            Simpan perubahan
                                        </Button>
                                    )}

                                    <Button
                                        type="button"
                                        variant="ghost"
                                        className="h-11"
                                        onClick={() => {
                                            setSheetBuka(false);
                                        }}
                                    >
                                        Batal
                                    </Button>
                                </div>
                            </SheetFooter>
                        </form>
                    ) : (
                        <div
                            role="status"
                            data-slot="admin-jadwal-sukses"
                            className="flex flex-col gap-3 px-4"
                        >
                            <p className="text-base font-medium">{sukses}</p>

                            <div data-testid="admin-aksi" className="flex flex-wrap gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="h-11"
                                    onClick={bukaTambah}
                                >
                                    Tambah jadwal lain
                                </Button>

                                <Button
                                    type="button"
                                    className="h-11"
                                    onClick={() => {
                                        setSheetBuka(false);
                                        setSukses(null);
                                    }}
                                >
                                    Selesai
                                </Button>
                            </div>
                        </div>
                    )}
                </SheetContent>
            </Sheet>
        </section>
    );
}

/**
 * The whole-day leave dates of one doctor.
 *
 * `dokter_libur` has no time columns, so the form has none either: the pattern's
 * rule is that the UI must not offer a half-day closure the database cannot
 * store, and the note says so. A duplicate date is refused by the server with a
 * 422 on `tanggal`, rendered inline.
 */
export function AdminLiburSection({ dokterId }: { dokterId: number }) {
    const online = useOnlineStatus();
    const queryClient = useQueryClient();

    const libur = useQuery(adminLiburOptions(dokterId));
    const baris = libur.data?.data.libur ?? [];

    const [tanggal, setTanggal] = useState('');
    const [alasan, setAlasan] = useState('');
    const [errorTanggal, setErrorTanggal] = useState<string[]>([]);

    const onlineSebelumnya = useRef(online);

    useEffect(() => {
        if (!onlineSebelumnya.current && online) {
            void libur.refetch();
        }

        onlineSebelumnya.current = online;
    }, [online, libur.refetch]);

    const simpan = useMutation({
        mutationFn: () =>
            simpanLibur(dokterId, {
                tanggal,
                ...(alasan.trim() === '' ? {} : { alasan: alasan.trim() }),
            }),
        onSuccess: (hasil) => {
            tambahLiburCache(queryClient, dokterId, hasil.data.libur);
            setTanggal('');
            setAlasan('');
            setErrorTanggal([]);
            toast.success('Libur ditambahkan.');
        },
        onError: (error) => {
            if (error instanceof ApiError && error.isValidation) {
                setErrorTanggal(
                    error.fieldErrors('tanggal').length > 0
                        ? error.fieldErrors('tanggal')
                        : [error.message],
                );

                return;
            }

            toast.error(pesanRingkas(error) ?? 'Libur gagal ditambahkan.');
        },
    });

    const hapus = useMutation({
        mutationFn: (id: number) => hapusLibur(id),
        onSuccess: (_hasil, id) => {
            hapusLiburCache(queryClient, dokterId, id);
            toast.success('Libur dihapus.');
        },
        onError: (error) => {
            toast.error(pesanRingkas(error) ?? 'Libur gagal dihapus.');
        },
    });

    return (
        <section className="flex flex-col gap-4" aria-label="Libur dokter">
            <h2 className="text-lg font-semibold">Libur</h2>

            <p className="text-muted-foreground text-sm">
                Libur berlaku seharian. Libur setengah hari belum didukung.
            </p>

            <form
                noValidate
                className="flex flex-col gap-3"
                onSubmit={(event) => {
                    event.preventDefault();

                    if (tanggal !== '') {
                        simpan.mutate();
                    }
                }}
            >
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <Field label="Tanggal" required errors={errorTanggal}>
                        <FieldInput
                            type="date"
                            value={tanggal}
                            onChange={(event) => {
                                setTanggal(event.target.value);
                            }}
                        />
                    </Field>

                    <Field label="Alasan (opsional)">
                        <FieldTextarea
                            rows={2}
                            value={alasan}
                            onChange={(event) => {
                                setAlasan(event.target.value);
                            }}
                        />
                    </Field>
                </div>

                <div data-testid="admin-aksi">
                    <Button
                        type="submit"
                        className="h-11"
                        data-testid="admin-tulis"
                        disabled={!online || tanggal === '' || simpan.isPending}
                    >
                        <Plus aria-hidden />
                        Tambah libur
                    </Button>
                </div>
            </form>

            {libur.isPending ? (
                <SkeletonRows rows={2} />
            ) : libur.isError ? (
                <AdminErrorState
                    title="Gagal memuat libur dokter."
                    error={libur.error}
                    onRetry={() => {
                        void libur.refetch();
                    }}
                />
            ) : baris.length === 0 ? (
                <EmptyState
                    title="Belum ada libur tercatat."
                    description="Tambahkan tanggal libur agar pasien tidak dapat memesan pada hari tersebut."
                />
            ) : (
                <ul role="list" className="flex flex-col gap-2">
                    {baris.map((row) => (
                        <li
                            key={row.id}
                            data-slot="admin-libur-row"
                            className="border-border flex items-center justify-between gap-3 rounded-lg border p-3"
                        >
                            <div className="min-w-0">
                                <p className="text-base font-medium tabular-nums">
                                    {tanggalSingkat(row.tanggal)}
                                </p>

                                {row.alasan === null ? null : (
                                    <p className="text-muted-foreground text-sm">
                                        {row.alasan}
                                    </p>
                                )}
                            </div>

                            <div data-testid="admin-aksi">
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="h-11"
                                    data-testid="admin-tulis"
                                    disabled={!online || hapus.isPending}
                                    onClick={() => {
                                        hapus.mutate(row.id);
                                    }}
                                >
                                    Hapus
                                </Button>
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
