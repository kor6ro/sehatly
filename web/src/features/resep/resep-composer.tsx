import { useState } from 'react';
import { useFieldArray, useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation } from '@tanstack/react-query';
import { Loader2, Plus, Send, ShieldAlert, Trash2 } from 'lucide-react';
import { ApiError } from '@/lib/http';
import { dispatchFlash } from '@/lib/flash';
import {
    buatResepMutation,
    buatResepSchema,
    type BuatResepInput,
} from '@/lib/api/resep';
import type { MasterObat, PeringatanGrup, Resep } from '@/lib/api/types';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Field, FieldInput, FieldTextarea, FormErrorSummary } from '@/components/form/field';
import { ObatAutocomplete } from '@/features/resep/obat-autocomplete';
import { WarningPanel } from '@/features/resep/warning-panel';

/**
 * The doctor's prescription composer, and the warn-then-override interaction.
 *
 * ## The override cannot be an unlabelled click, and it cannot be a client-side toggle
 *
 * This is the whole point of the screen, so the mechanism is worth stating plainly.
 *
 * 1. **The server decides, not the client.** `ResepService::buat()` runs the interaction
 *    engine and, if any warning is a `kontraindikasi`, refuses the request with a 422 on
 *    `catatan_dodio`. The composer does not pre-judge anything; it submits and reads the
 *    answer. A client that kept its own copy of the interaction table would be a second,
 *    stale, unsourced copy of a clinical rule.
 * 2. **Nothing is written when the warning fires.** `catatan()` throws BEFORE
 *    `tulisDenganNomorUnik()`, so the 422 leaves no `resep` row. The re-submit after the
 *    acknowledgement therefore creates the prescription exactly once, and the gate can be
 *    re-driven without risking a duplicate.
 * 3. **The gate is a section of the form, not a dialog.** {@link OverrideGate} has no
 *    close button, no escape handler and no outside-click handler, because there is nothing
 *    to dismiss: the submit stays disabled until the gate's own two conditions hold. A
 *    dialog can be dismissed by muscle memory; a disabled button cannot be clicked at all.
 * 4. **Two conditions, both explicit.** A labelled checkbox whose visible text NAMES the
 *    risk, and a non-empty note. Neither is inferable from the other, so neither alone
 *    opens the gate - and a click anywhere on the banner, on the item list, or on the
 *    submit button while the gate is closed does nothing.
 * 5. **The record is server-side.** The note travels as `catatan_dodio` and is stored in
 *    `resep.catatan_dokter`, which the request marks `prohibited` so it can only ever be
 *    written AS an acknowledgement. There is no client-side "I overrode this" flag to lose.
 *
 * ## The doctor is never blocked from ADDING an item
 *
 * A clashing drug is added, the warning travels with it, and the decision to keep it is a
 * separate recorded act. Removing the drug would hide the doctor's choice from the
 * pharmacist, which is the opposite of what a contraindication warning is for.
 */
export function ResepComposer({
    konsultasiId,
    onSelesai,
}: {
    konsultasiId: number;
    onSelesai?: (resep: Resep) => void;
}) {
    const simpan = useMutation(buatResepMutation(konsultasiId));

    /**
     * The gate is open only while a refusal is on screen.
     *
     * It is derived from the mutation's own error rather than from a separate boolean, so it
     * cannot be left open by a retry, a re-render, or an unrelated validation failure. When
     * there is no refusal the list is empty, the gate is not rendered, and the submit button
     * re-enables by itself.
     */
    const ditolakCatatan =
        simpan.error instanceof ApiError && simpan.error.isValidation
            ? simpan.error.fieldErrors('catatan_dodio')
            : [];

    const ditolak = ditolakCatatan.length > 0;

    const [sudahBaca, setSudahBaca] = useState(false);
    const [grup, setGrup] = useState<PeringatanGrup | null>(null);
    const [resepTersimpan, setResepTersimpan] = useState<Resep | null>(null);

    const form = useForm<BuatResepInput>({
        resolver: zodResolver(buatResepSchema),
        defaultValues: { items: [] },
    });

    const { fields, append, remove } = useFieldArray({
        control: form.control,
        name: 'items',
    });

    const kirim = form.handleSubmit(async (nilai) => {
        try {
            const hasil = await simpan.mutateAsync(nilai);

            setGrup(hasil.data.warning_grup);
            setResepTersimpan(hasil.data.resep);
            setSudahBaca(false);

            dispatchFlash({ level: 'success', message: hasil.message });

            onSelesai?.(hasil.data.resep);
        } catch (error) {
            /**
             * A refusal on `catatan_dodio` is the warn step, not a failure to report as one.
             * It is rendered by the gate instead, with the server's own message, and the
             * doctor is told nothing was saved.
             */
            if (
                error instanceof ApiError &&
                error.isValidation &&
                error.fieldErrors('catatan_dodio').length > 0
            ) {
                setSudahBaca(false);
                setGrup(null);

                return;
            }

            dispatchFlash({
                level: 'error',
                message:
                    error instanceof ApiError
                        ? error.message
                        : 'Permintaan gagal.',
            });
        }
    });

    const catatan = form.watch('catatan_dodio') ?? '';

    /**
     * The submit is disabled while the gate is open and not yet satisfied. A note the doctor
     * typed BEFORE the warning appeared does not open the gate: the acknowledgement has to
     * be made after the warning is on screen, which is the whole point of recording it.
     */
    const terkunci = ditolak && (!sudahBaca || catatan.trim() === '');

    /**
     * The commit, and the guard is INSIDE the handler as well as on the button.
     *
     * `disabled` alone is the whole defence for a real user - a browser does not dispatch a
     * click on a disabled button - but it is not the whole defence in general. Playwright's
     * `click({ force: true })` dispatches the event anyway, and this composer was driven by
     * exactly that: an earlier version of the spec force-clicked the locked button and the
     * prescription was written anyway, because the guard lived only in the attribute. So the
     * same condition is re-checked here, and the note is re-read from the form rather than
     * from the render that produced the attribute.
     *
     * A client that lets a DOM attribute enforce a clinical decision is relying on the
     * browser being honest. This does not.
     */
    const commit = (): void => {
        if (simpan.isPending) {
            return;
        }

        if (
            ditolak &&
            (!sudahBaca || (form.getValues('catatan_dodio') ?? '').trim() === '')
        ) {
            return;
        }

        void kirim();
    };

    return (
        <Card data-slot="resep-composer">
            <CardHeader>
                <CardTitle>Resep elektronik</CardTitle>

                <CardDescription>
                    Resep ditulis pada konsultasi yang sedang berlangsung. Peringatan
                    interaksi obat selalu ditampilkan, tidak pernah disembunyikan.
                </CardDescription>
            </CardHeader>

            <CardContent className="flex flex-col gap-4">
                <FormErrorSummary error={simpan.error} />

                {ditolak ? (
                    <OverrideGate
                        pesan={ditolakCatatan}
                        sudahBaca={sudahBaca}
                        catatan={catatan}
                        onUbahBaca={setSudahBaca}
                    />
                ) : null}

                {/**
                 * The panel after a successful save, so the doctor sees exactly what the
                 * pharmacist will see - including the clashing pair, which the 422 could
                 * only say existed.
                 */}
                {grup === null ? null : (
                    <WarningPanel grup={grup} />
                )}

                {resepTersimpan === null ? null : (
                    <p
                        data-slot="resep-tersimpan"
                        className="text-success text-sm"
                    >
                        Resep {resepTersimpan.nomor_resep} tersimpan, berlaku sampai{' '}
                        {resepTersimpan.berlaku_sampai}.
                    </p>
                )}

                <ObatAutocomplete
                    disabled={simpan.isPending}
                    onPilih={(obat: MasterObat) => {
                        append({
                            obat_id: obat.id,
                            nama_obat: obat.nama_generik,
                            kekuatan: obat.kekuatan,
                            aturan_pakai: obat.aturan_pakai_umum ?? '',
                            jumlah: 1,
                            satuan: obat.satuan,
                            is_racikan: false,
                        });
                    }}
                />

                {fields.length === 0 ? (
                    <p
                        data-slot="resep-kosong"
                        className="text-muted-foreground text-sm"
                    >
                        Belum ada obat. Pilih dari katalog di atas.
                    </p>
                ) : (
                    <ul data-slot="resep-items" className="flex flex-col gap-3">
                        {fields.map((bidang, index) => (
                            <li
                                key={bidang.id}
                                data-slot="resep-item"
                                className="bg-muted/30 flex flex-col gap-3 rounded-md border p-3"
                            >
                                <div className="flex items-start justify-between gap-2">
                                    <div className="flex flex-col gap-0.5">
                                        <p className="text-sm font-medium">
                                            {form.watch(`items.${index}.nama_obat`) ??
                                                '-'}
                                        </p>

                                        <p className="text-muted-foreground text-xs">
                                            {form.watch(`items.${index}.kekuatan`) ?? ''}
                                        </p>
                                    </div>

                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        aria-label={`Hapus baris ${index + 1}`}
                                        disabled={simpan.isPending}
                                        onClick={() => {
                                            remove(index);
                                        }}
                                    >
                                        <Trash2 />
                                    </Button>
                                </div>

                                <div className="grid gap-3 sm:grid-cols-2">
                                    <Field
                                        label="Aturan pakai"
                                        required
                                        errors={fieldErrors(
                                            simpan.error,
                                            `items.${index}.aturan_pakai`,
                                        )}
                                    >
                                        <FieldTextarea
                                            rows={2}
                                            maxLength={255}
                                            disabled={simpan.isPending}
                                            {...form.register(
                                                `items.${index}.aturan_pakai`,
                                            )}
                                        />
                                    </Field>

                                    <Field
                                        label="Jumlah"
                                        required
                                        errors={fieldErrors(
                                            simpan.error,
                                            `items.${index}.jumlah`,
                                        )}
                                    >
                                        <FieldInput
                                            type="number"
                                            min={1}
                                            max={65_535}
                                            disabled={simpan.isPending}
                                            {...form.register(
                                                `items.${index}.jumlah`,
                                                { valueAsNumber: true },
                                            )}
                                        />
                                    </Field>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}

                {/**
                 * Always present, and only marked required when the gate is open.
                 *
                 * The field is not created by the warning because the server is what demands
                 * it: `StoreResepRequest` allows `catatan_dodio` on every request, and
                 * `ResepService::catatan()` only refuses when a `kontraindikasi` is present.
                 * A doctor with no interactions therefore sends no note at all, and
                 * `catatan_dokter` stays NULL, which is the truthful record.
                 */}
                <Field
                    label={
                        ditolak
                            ? 'Catatan pengakuan (wajib diisi)'
                            : 'Catatan dokter (opsional)'
                    }
                    required={ditolak}
                    hint={
                        ditolak
                            ? 'Tersimpan pada resep.catatan_dokter sebagai bukti bahwa peringatan telah dibaca dan diputuskan.'
                            : 'Tersimpan pada resep.catatan_dokter.'
                    }
                    errors={ditolakCatatan}
                >
                    <FieldTextarea
                        rows={3}
                        maxLength={16_000}
                        disabled={simpan.isPending}
                        {...form.register('catatan_dodio')}
                    />
                </Field>
            </CardContent>

            <CardFooter className="flex flex-wrap gap-2">
                <Button
                    type="button"
                    onClick={() => {
                        simpan.reset();
                        setSudahBaca(false);
                        setGrup(null);
                        setResepTersimpan(null);
                    }}
                    variant="outline"
                    disabled={simpan.isPending}
                >
                    <Plus />

                    Reset
                </Button>

                <Button
                    type="button"
                    disabled={simpan.isPending || terkunci}
                    onClick={commit}
                >
                    {simpan.isPending ? (
                        <Loader2 className="animate-spin" />
                    ) : ditolak ? (
                        <ShieldAlert />
                    ) : (
                        <Send />
                    )}

                    {ditolak ? 'Kirim dengan pengakuan' : 'Simpan resep'}
                </Button>
            </CardFooter>
        </Card>
    );
}

/**
 * The override gate: blocking, non-dismissible, and satisfied only by two explicit acts.
 *
 * ## Why there is no way to close this
 *
 * The component deliberately has no close button, no `Dialog`, and no escape or
 * outside-click handler. A doctor who clicks straight through a contraindication warning is
 * the exact harm this feature exists to prevent, so the gate has exactly two exits: tick
 * the labelled box, and write a note. Until both are done the submit button is disabled, so
 * there is no click - labelled or not - that commits the prescription.
 *
 * The visible label NAMES what is being overridden. A checkbox reading "Saya mengerti" is
 * one stray click away from being a rubber stamp; one reading what the drug clash is
 * requires the doctor to have read the banner above it.
 */
function OverrideGate({
    pesan,
    sudahBaca,
    catatan,
    onUbahBaca,
}: {
    pesan: string[];
    sudahBaca: boolean;
    catatan: string;
    onUbahBaca: (nilai: boolean) => void;
}) {
    const id = 'resep-override-ack';

    return (
        <section
            data-slot="resep-override-gate"
            data-terbuka="true"
            aria-labelledby="resep-override-judul"
            className="border-destructive bg-destructive/5 flex flex-col gap-3 rounded-lg border-2 p-4"
        >
            <div className="flex items-start gap-2">
                <ShieldAlert aria-hidden className="text-destructive mt-0.5 size-5 shrink-0" />

                <div className="flex flex-col gap-1">
                    <h3
                        id="resep-override-judul"
                        className="text-destructive text-sm font-semibold"
                    >
                        Peringatan kontraindikasi
                    </h3>

                    <p className="text-sm">
                        Server menolak menyimpan resep ini tanpa catatan. Tidak ada resep
                        yang ditulis - mengulangi dan mengisi catatan akan membuat satu
                        resep.
                    </p>

                    <ul className="flex flex-col gap-0.5">
                        {pesan.map((m) => (
                            <li key={m} className="text-muted-foreground text-xs">
                                {m}
                            </li>
                        ))}
                    </ul>
                </div>
            </div>

            <div className="flex items-start gap-2">
                <Checkbox
                    id={id}
                    data-slot="resep-override-checkbox"
                    checked={sudahBaca}
                    onCheckedChange={(nilai) => {
                        onUbahBaca(nilai === true);
                    }}
                />

                <label
                    htmlFor={id}
                    className="text-sm leading-snug font-medium select-none"
                >
                    Saya telah membaca peringatan kontraindikasi di atas dan tetap
                    meresepkan kombinasi obat ini. Pengakuan saya akan dicatat pada
                    resep.
                </label>
            </div>

            <p
                data-slot="resep-override-sisa"
                className="text-muted-foreground text-xs"
            >
                {sudahBaca
                    ? catatan.trim() === ''
                        ? 'Tinggal mengisi catatan pengakuan.'
                        : 'Siap dikirim. Catatan akan tersimpan pada resep.'
                    : 'Centang pengakuan dan isi catatan untuk membuka tombol kirim.'}
            </p>
        </section>
    );
}

function fieldErrors(error: unknown, kolom: string): string[] {
    return error instanceof ApiError ? error.fieldErrors(kolom) : [];
}
