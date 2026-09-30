import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { useMutation, useQuery } from '@tanstack/react-query';
import { Paperclip, Plus, Send, Trash2 } from 'lucide-react';
import {
    createBookingMutation,
    labelTipeLayanan,
    TIPE_LAYANAN,
    type CreateBookingInput,
} from '@/lib/api/booking';
import { anggotaKeluargaOptions, namaHubungan } from '@/lib/api/anggota-keluarga';
import { BookingCalendar } from '@/features/booking/booking-calendar';
import { SlotPicker, SlotTakenNotice } from '@/features/booking/slot-picker';
import { jamKeHms } from '@/lib/tanggal';
import { ApiError } from '@/lib/http';
import { formatRupiah } from '@/lib/format';
import type { Decimal } from '@/lib/api/types';
import { dispatchFlash } from '@/lib/flash';
import { Field, FieldInput, FieldSelect, FieldTextarea, FormErrorSummary } from '@/components/form/field';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { SelectItem } from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';

/**
 * `POST /api/v1/booking`, with the calendar and the slot picker as its two first-class
 * inputs rather than as decoration above it.
 *
 * ## The submit button is disabled, and the reason is on screen
 *
 * A date and a start time are both `required` on the server (`date_format:Y-m-d` and
 * `date_format:H:i:s`) and neither can be defaulted: defaulting the date to today would
 * invite a booking for a slot that has already ended, and defaulting the time would be
 * inventing a slot. So the button stays disabled while either is missing and a line
 * beside it says which one. Pressing it anyway would only produce a 422 the form already
 * knows how to prevent.
 *
 * ## Nothing is written to the cache before the server answers
 *
 * There is no `onMutate` and no optimistic insert. A booking is the one write in this app
 * where being wrong is expensive: the server answers a competing `POST /booking` with 422
 * on `slot` precisely so a client that guessed availability cannot double-book, and an
 * optimistic row would put a booking on screen that the server has just refused. The list
 * is invalidated in the mutation's `onSuccess` and the created row is handed to the caller
 * to display from the **response**, so every row on screen came from the server.
 */
const schema = z.object({
    tipe_layanan: z.enum(['chat', 'video_call', 'kunjungan_klinik', 'home_visit']),
    tanggal_kunjungan: z
        .string()
        .regex(/^\d{4}-\d{2}-\d{2}$/, 'Tanggal kunjungan harus format YYYY-MM-DD.'),
    slot_mulai: z
        .string()
        .regex(/^\d{2}:\d{2}:\d{2}$/, 'Jam mulai harus format HH:MM:SS.'),
    keluhan: z.string().trim().max(5000, 'Keluhan maksimal 5000 karakter.'),
    anggota_keluarga_id: z.string(),
    is_rujukan: z.boolean(),
    is_konsultasi_lanjutan: z.boolean(),
});

type BookingForm = z.infer<typeof schema>;

/**
 * One attachment row in local state.
 *
 * Kept out of react-hook-form on purpose: the field is a repeatable list, and a
 * `z.array` inside a resolver means one malformed entry blocks the whole submit with a
 * message attached to an input that may not be the one at fault. Validating each row where
 * it is rendered, and sending the array, keeps the failure local to the field that caused
 * it. The server still validates both members, and its answer is rendered through
 * `lampiran_keluhan`.
 */
type Lampiran = {
    kunci: string;
    nama: string;
    url: string;
};

export function BookingForm({
    dokterId,
    dokterNama,
    biaya,
    tanggalAwal,
    jamAwal,
    onCreated,
}: {
    dokterId: string;
    dokterNama: string;
    /** `dokter.biaya_konsultasi_online`, a `DECIMAL` that arrives as a JSON string. */
    biaya: Decimal;
    /** `Y-m-d` deep-link, or `null`. Lets a booking link name its own date. */
    tanggalAwal: string | null;
    /**
     * `H:i:s` deep-link, or `null`. It seeds both the manual time box and the submitted
     * `slot_mulai`, because it **is** the patient's answer to the picker: a booking link
     * that names a time and then refused to carry it would be a link that does not work.
     * The value is not trusted - the server re-validates it and a refusal is rendered on
     * the `slot` field - and a malformed one is ignored so the form asks again.
     */
    jamAwal: string | null;
    onCreated: (nomorBooking: string) => void;
}) {
    const [serverError, setServerError] = useState<unknown>(null);
    const [jamManual, setJamManual] = useState(
        jamAwal !== null && /^\d{2}:\d{2}:\d{2}$/.test(jamAwal)
            ? jamAwal.slice(0, 5)
            : '',
    );
    const [lampiran, setLampiran] = useState<Lampiran[]>([]);

    const keluarga = useQuery(anggotaKeluargaOptions({ page: 1, per_page: 100 }));

    const {
        register,
        handleSubmit,
        setValue,
        watch,
        formState: { errors },
    } = useForm<BookingForm>({
        resolver: zodResolver(schema),
        defaultValues: {
            tipe_layanan: 'video_call',
            tanggal_kunjungan:
                tanggalAwal !== null && /^\d{4}-\d{2}-\d{2}$/.test(tanggalAwal)
                    ? tanggalAwal
                    : '',
            slot_mulai:
                jamAwal !== null && /^\d{2}:\d{2}:\d{2}$/.test(jamAwal)
                    ? jamAwal
                    : '',
            keluhan: '',
            anggota_keluarga_id: '',
            is_rujukan: false,
            is_konsultasi_lanjutan: false,
        },
    });

    const create = useMutation(createBookingMutation());

    const tanggal = watch('tanggal_kunjungan');
    const tipeLayanan = watch('tipe_layanan');
    const anggotaKeluargaId = watch('anggota_keluarga_id');
    const isRujukan = watch('is_rujukan');
    const isLanjutan = watch('is_konsultasi_lanjutan');

    const slotMulai = watch('slot_mulai');
    const couldSubmit = tanggal !== '' && slotMulai !== '';

    function pilihJam(value: string): void {
        setValue('slot_mulai', value, { shouldValidate: true, shouldDirty: true });
    }

    function ubahLampiran(
        index: number,
        kolom: 'nama' | 'url',
        nilai: string,
    ): void {
        setLampiran((current) =>
            current.map((row, posisi) =>
                posisi === index ? { ...row, [kolom]: nilai } : row,
            ),
        );
    }

    async function onSubmit(values: BookingForm): Promise<void> {
        setServerError(null);

        try {
            const result = await create.mutateAsync(
                toPayload(values, dokterId, lampiran),
            );

            dispatchFlash({ level: 'success', message: result.message });

            onCreated(result.data.booking.nomor_booking);
        } catch (error) {
            /**
             * Deliberately not clearing the form. A 422 on `slot` means the time was
             * refused and the patient needs to pick another one with their complaint still
             * typed; resetting would ask them to retype it for a mistake the server made,
             * not them.
             */
            setServerError(error);
        }
    }

    const slotTaken = serverError instanceof ApiError && serverError.isSlotTaken;

    return (
        <form
            onSubmit={handleSubmit(onSubmit)}
            noValidate
            className="flex flex-col gap-6"
        >
            <FormErrorSummary error={serverError} />

            {serverError instanceof ApiError && !serverError.isValidation ? (
                <p className="text-destructive text-sm">{serverError.message}</p>
            ) : null}

            {slotTaken ? <SlotTakenNotice messages={serverError.fieldErrors('slot')} /> : null}

            <Card>
                <CardHeader>
                    <CardTitle>1. Tanggal kunjungan</CardTitle>
                </CardHeader>

                <CardContent>
                    <BookingCalendar
                        value={tanggal === '' ? null : tanggal}
                        onChange={(value) => {
                            setValue('tanggal_kunjungan', value, {
                                shouldValidate: true,
                                shouldDirty: true,
                            });

                            /**
                             * Changing the date invalidates the chosen time, because a
                             * `slot_mulai` is only meaningful against the date it was
                             * offered for. Carrying `09:00` from Monday to Tuesday would
                             * submit a time the server never published for that day.
                             */
                            setValue('slot_mulai', '', {
                                shouldValidate: false,
                                shouldDirty: true,
                            });
                        }}
                    />

                    <div className="mt-3">
                        <Field
                            label="Tanggal kunjungan"
                            errors={messages(errors.tanggal_kunjungan?.message)}
                            hint="Dikirim sebagai Y-m-d, misalnya 2026-10-05."
                        >
                            <FieldInput
                                value={tanggal}
                                readOnly
                                placeholder="Pilih tanggal pada kalender"
                            />
                        </Field>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>2. Jam mulai</CardTitle>
                </CardHeader>

                <CardContent className="flex flex-col gap-4">
                    <SlotPicker
                        dokterId={dokterId}
                        tanggal={tanggal === '' ? null : tanggal}
                        selected={slotMulai === '' ? null : slotMulai}
                        onSelect={pilihJam}
                        jamManual={jamManual}
                        onJamManualChange={(value) => {
                            setJamManual(value);

                            pilihJam(jamKeHms(value));
                        }}
                    />

                    <Field
                        label="Jam mulai terpilih"
                        errors={[
                            ...messages(errors.slot_mulai?.message),
                            ...fieldErrors(serverError, 'slot_mulai'),
                            ...(slotTaken ? fieldErrors(serverError, 'slot') : []),
                        ]}
                        hint="Jam dikirim sebagai H:i-s dan divalidasi penuh oleh server."
                    >
                        <FieldInput
                            value={slotMulai}
                            readOnly
                            placeholder="Belum ada jam yang dipilih"
                        />
                    </Field>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>3. Detail kunjungan</CardTitle>
                </CardHeader>

                <CardContent className="flex flex-col gap-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="Tipe layanan"
                            errors={[
                                ...messages(errors.tipe_layanan?.message),
                                ...fieldErrors(serverError, 'tipe_layanan'),
                            ]}
                            required
                        >
                            <FieldSelect
                                value={tipeLayanan}
                                onValueChange={(value) => {
                                    setValue(
                                        'tipe_layanan',
                                        value as BookingForm['tipe_layanan'],
                                        { shouldValidate: true },
                                    );
                                }}
                            >
                                {TIPE_LAYANAN.map((row) => (
                                    <SelectItem key={row} value={row}>
                                        {labelTipeLayanan(row)}
                                    </SelectItem>
                                ))}
                            </FieldSelect>
                        </Field>

                        {/**
                         * The family list is real data from
                         * `GET /api/v1/pasien/anggota-keluarga`, and it can legitimately
                         * fail: the same account may own no `pasien` row, which is a 403
                         * the create itself would also receive. Hiding the control and
                         * saying so is better than offering a dropdown that is empty
                         * because the query is still loading, or because it failed.
                         */}
                        <Field
                            label="Didaftarkan atas nama"
                            errors={fieldErrors(
                                serverError,
                                'anggota_keluarga_id',
                            )}
                            hint="Dikosongkan berarti untuk pasien sendiri."
                        >
                            <FieldSelect
                                value={anggotaKeluargaId}
                                disabled={keluarga.isError}
                                onValueChange={(value) => {
                                    setValue('anggota_keluarga_id', value, {
                                        shouldValidate: true,
                                    });
                                }}
                            >
                                <SelectItem value="">
                                    Pasien sendiri
                                </SelectItem>

                                {(keluarga.data?.data.anggota_keluarga ?? []).map(
                                    (row) => (
                                        <SelectItem
                                            key={row.id}
                                            value={String(row.id)}
                                        >
                                            {row.nama_lengkap} (
                                            {namaHubungan(
                                                row.hubungan_id,
                                                row.hubungan,
                                            )}
                                            )
                                        </SelectItem>
                                    ),
                                )}
                            </FieldSelect>
                        </Field>
                    </div>

                    {keluarga.isError ? (
                        <p className="text-muted-foreground text-sm">
                            Daftar anggota keluarga tidak dapat dimuat, sehingga
                            booking hanya dapat dibuat untuk pasien sendiri.
                        </p>
                    ) : null}

                    <Field
                        label="Keluhan"
                        errors={[
                            ...messages(errors.keluhan?.message),
                            ...fieldErrors(serverError, 'keluhan'),
                        ]}
                        hint="Opsional. Tulis keluhan agar dokter dapat mempersiapkan konsultasi."
                    >
                        <FieldTextarea
                            rows={5}
                            placeholder="Contoh: demam tiga hari, sakit kepala, dan hilang nafsu makan."
                            {...register('keluhan')}
                        />
                    </Field>

                    <div className="flex flex-col gap-3 rounded-lg border p-3">
                        <p className="text-sm font-medium">
                            <Paperclip aria-hidden className="mr-1.5 inline size-4" />

                            Lampiran keluhan
                        </p>

                        {/**
                         * `lampiran_keluhan` is a JSON column of `{nama, url}` pairs
                         * validated as `nama: required|string|max:150` and
                         * `url: required|url|max:2048`. **This API publishes no file-upload
                         * endpoint**: `route:list --path=api/v1` carries 26 routes and none
                         * of them stores a file, and the plan's "posting to the storage
                         * endpoint" names an endpoint that was never registered. So the
                         * field takes a URL the patient already has, rather than a file
                         * picker that would have to fake an upload - no `data:` URI, no
                         * local object URL, nothing the server could not later resolve.
                         */}
                        <p className="text-muted-foreground text-xs">
                            Lampiran diisi dengan nama berkas dan URL yang sudah Anda
                            unggah sendiri. Nama maksimal 150 karakter dan URL harus
                            valid.
                        </p>

                        <div className="flex flex-col gap-3">
                            {lampiran.map((item, index) => (
                                <div
                                    key={item.kunci}
                                    className="grid gap-3 sm:grid-cols-[1fr_2fr_auto]"
                                >
                                    <Field
                                        label={`Nama berkas ${String(index + 1)}`}
                                        errors={messages(
                                            item.nama.length > 150
                                                ? 'Nama berkas maksimal 150 karakter.'
                                                : undefined,
                                        )}
                                    >
                                        <FieldInput
                                            value={item.nama}
                                            placeholder="hasil-lab.pdf"
                                            onChange={(event) => {
                                                ubahLampiran(
                                                    index,
                                                    'nama',
                                                    event.target.value,
                                                );
                                            }}
                                        />
                                    </Field>

                                    <Field
                                        label={`URL berkas ${String(index + 1)}`}
                                        errors={[
                                            ...messages(
                                                item.url !== '' &&
                                                    !/^https?:\/\/\S+$/.test(item.url)
                                                    ? 'URL harus diawali http:// atau https://.'
                                                    : undefined,
                                            ),
                                            ...fieldErrors(
                                                serverError,
                                                'lampiran_keluhan',
                                            ),
                                        ]}
                                    >
                                        <FieldInput
                                            value={item.url}
                                            placeholder="https://contoh.example/hasil-lab.pdf"
                                            onChange={(event) => {
                                                ubahLampiran(
                                                    index,
                                                    'url',
                                                    event.target.value,
                                                );
                                            }}
                                        />
                                    </Field>

                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="icon"
                                        aria-label={`Hapus lampiran ${String(index + 1)}`}
                                        className="self-end"
                                        onClick={() => {
                                            setLampiran((current) =>
                                                current.filter(
                                                    (row) =>
                                                        row.kunci !== item.kunci,
                                                ),
                                            );
                                        }}
                                    >
                                        <Trash2 />
                                    </Button>
                                </div>
                            ))}

                            <div>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => {
                                        setLampiran((current) => [
                                            ...current,
                                            {
                                                kunci: String(
                                                    current.length + 1,
                                                ) + '-' + String(Date.now()),
                                                nama: '',
                                                url: '',
                                            },
                                        ]);
                                    }}
                                >
                                    <Plus />

                                    Tambah lampiran
                                </Button>
                            </div>
                        </div>
                    </div>

                    <div className="flex flex-col gap-3">
                        <div className="flex items-center gap-2">
                            <Checkbox
                                id="is_rujukan"
                                checked={isRujukan}
                                onCheckedChange={(checked) => {
                                    setValue('is_rujukan', checked === true);
                                }}
                            />

                            <Label htmlFor="is_rujukan" className="font-normal">
                                Booking ini merupakan rujukan
                            </Label>
                        </div>

                        <div className="flex items-center gap-2">
                            <Checkbox
                                id="is_konsultasi_lanjutan"
                                checked={isLanjutan}
                                onCheckedChange={(checked) => {
                                    setValue(
                                        'is_konsultasi_lanjutan',
                                        checked === true,
                                    );
                                }}
                            />

                            <Label
                                htmlFor="is_konsultasi_lanjutan"
                                className="font-normal"
                            >
                                Booking ini merupakan konsultasi lanjutan
                            </Label>
                        </div>

                        <p className="text-muted-foreground text-xs">
                            Rujukan adalah keterangan asal-usul, bukan potongan
                            harga: tagihan tetap sebesar biaya konsultasi dokter
                            yang ditampilkan di bawah.
                        </p>
                    </div>
                </CardContent>
            </Card>

            <div className="flex flex-col gap-3">
                <div className="text-muted-foreground flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                    <span>Dokter: {dokterNama}</span>

                    <span>Biaya: {formatRupiah(biaya)}</span>
                </div>

                {couldSubmit ? null : (
                    <p className="text-muted-foreground text-sm">
                        {tanggal === ''
                            ? 'Pilih tanggal kunjungan untuk melanjutkan.'
                            : 'Pilih jam mulai untuk melanjutkan.'}
                    </p>
                )}

                <div>
                    <Button
                        type="submit"
                        disabled={!couldSubmit || create.isPending}
                    >
                        {create.isPending ? (
                            <Spinner />
                        ) : (
                            <>
                                <Send />

                                Kirim booking
                            </>
                        )}
                    </Button>
                </div>
            </div>
        </form>
    );
}

/**
 * Form state -> the request body, with every optional key omitted rather than sent null.
 *
 * Three decisions here are the difference between a 201 and a 422:
 *
 * - `pasien_id` is **absent**. The server marks it `prohibited`, so sending it is a
 *   refusal rather than a no-op, and the tenant key is written from the caller's own row.
 * - `jadwal_id` is **absent**, because there is no endpoint that publishes which
 *   `dokter_jadwal` row a slot came from. A `Select` offering ids the client cannot
 *   legitimately know would be inventing a value; the server resolves the geometry from
 *   the published slot instead.
 * - `nomor_antrian` is **absent**, because the DDL declares the column and nothing
 *   enforces it, so the create leaves it `null` and the resource publishes `null`.
 */
function toPayload(
    values: BookingForm,
    dokterId: string,
    lampiran: Lampiran[],
): CreateBookingInput {
    /**
     * Rows with only one member filled are dropped rather than sent half-empty, because
     * the server requires **both** and would answer a 422 on `lampiran_keluhan.0.nama`
     * for a row the user had not finished typing.
     */
    const terlampir = lampiran
        .map((row) => ({ nama: row.nama.trim(), url: row.url.trim() }))
        .filter((row) => row.nama !== '' && row.url !== '');

    return {
        dokter_id: Number(dokterId),
        tipe_layanan: values.tipe_layanan,
        tanggal_kunjungan: values.tanggal_kunjungan,
        slot_mulai: values.slot_mulai,
        ...(values.keluhan.trim() === ''
            ? {}
            : { keluhan: values.keluhan.trim() }),
        ...(terlampir.length === 0 ? {} : { lampiran_keluhan: terlampir }),
        ...(values.anggota_keluarga_id === ''
            ? {}
            : { anggota_keluarga_id: Number(values.anggota_keluarga_id) }),
        ...(values.is_rujukan ? { is_rujukan: true } : {}),
        ...(values.is_konsultasi_lanjutan
            ? { is_konsultasi_lanjutan: true }
            : {}),
    };
}

function messages(clientMessage: string | undefined): string[] {
    return clientMessage === undefined ? [] : [clientMessage];
}

function fieldErrors(error: unknown, field: string): string[] {
    if (!(error instanceof ApiError)) {
        return [];
    }

    return error.fieldErrors(field);
}
