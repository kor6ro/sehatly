import { useState } from 'react';
import type { FormEvent } from 'react';
import { useMutation } from '@tanstack/react-query';
import { Link } from 'react-router';
import { AlertCircle, CheckCircle2, Loader2, Send } from 'lucide-react';
import { ApiError } from '@/lib/http';
import { simpanUlasanMutation } from '@/lib/api/ulasan';
import type { Konsultasi } from '@/lib/api/types';
import { useOnlineStatus } from '@/hooks/use-online-status';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { PilihBintang } from '@/features/ulasan/pilih-bintang';

/** The product ceiling, `SimpanUlasanRequest::ISI_MAKS`. */
const ISI_MAKS = 1000;

/**
 * The patient's review form for one `selesai` consultation.
 *
 * ## Five fields, and only one of them is required
 *
 * `rating` is required 1-5; `rating_komunikasi`, `rating_akurasi` and `isi` are
 * optional and the service stores `null` for an absent one. `is_anonim` defaults to
 * **checked**, matching the DDL default of `1` and the F04 privacy rule: a patient opts
 * in to being named, never out. The body has no clinical field at all - a review is an
 * experience note, not a record.
 *
 * ## Success is a generic confirmation
 *
 * After a 201 the form is replaced by a sentence that names no consultation content and
 * a way back to the consultation or the doctor's public profile. There is no edit path:
 * `ulasan_dokter` has no update endpoint (`dibuat_at` is its only timestamp), so the
 * UI must not offer one.
 *
 * ## A 422 is rendered where it happened
 *
 * The server's two write refusals are `Konsultasi ini sudah memiliki ulasan.`
 * (`errors.konsultasi_id`, translated from MySQL's 1062) and `Ulasan hanya dapat
 * ditulis setelah konsultasi selesai.` (`errors.status`). Both arrive as a 422 whose
 * `errors` block is flattened into one inline alert above the submit button; a missing
 * overall rating is caught locally before a request is spent, through the same
 * `role="alert"` mechanism under the picker.
 *
 * ## Offline blocks the write and says so
 *
 * `_global.md` §7 forbids queueing a write. The submit keeps `aria-disabled` rather than
 * disappearing, and the reason is a visible sentence - a button that silently does
 * nothing is worse than one that explains.
 */
export function FormUlasan({ konsultasi }: { konsultasi: Konsultasi }) {
    const online = useOnlineStatus();

    const [rating, setRating] = useState<number | null>(null);
    const [komunikasi, setKomunikasi] = useState<number | null>(null);
    const [akurasi, setAkurasi] = useState<number | null>(null);
    const [isi, setIsi] = useState('');
    const [anonim, setAnonim] = useState(true);
    const [pesanLokal, setPesanLokal] = useState<string | null>(null);

    const kirimUlasan = useMutation(simpanUlasanMutation(konsultasi.id));

    if (kirimUlasan.isSuccess) {
        return (
            <Card data-slot="ulasan-sukses">
                <CardContent className="pt-6">
                    <Alert role="status">
                        <CheckCircle2 aria-hidden className="text-success" />

                        <AlertTitle>Ulasan berhasil dikirim.</AlertTitle>

                        <AlertDescription>
                            <p>
                                Terima kasih. Ulasan Anda membantu pasien lain
                                memilih dokter.
                            </p>

                            <div className="mt-2 flex flex-wrap gap-2">
                                <Button
                                    asChild
                                    variant="outline"
                                    className="min-h-11"
                                >
                                    <Link to={`/konsultasi/${String(konsultasi.id)}`}>
                                        Kembali ke konsultasi
                                    </Link>
                                </Button>

                                {konsultasi.dokter === undefined ? null : (
                                    <Button
                                        asChild
                                        variant="outline"
                                        className="min-h-11"
                                    >
                                        <Link
                                            to={`/dokter/${String(konsultasi.dokter.id)}`}
                                        >
                                            Lihat profil dokter
                                        </Link>
                                    </Button>
                                )}
                            </div>
                        </AlertDescription>
                    </Alert>
                </CardContent>
            </Card>
        );
    }

    const galatApi =
        kirimUlasan.error instanceof ApiError ? kirimUlasan.error : null;

    const pesanGalat =
        galatApi === null
            ? []
            : galatApi.isValidation
              ? Object.values(galatApi.errors).flat()
              : [galatApi.message];

    const kirim = (event: FormEvent<HTMLFormElement>): void => {
        event.preventDefault();

        if (!online) {
            return;
        }

        // The client-side half of the server's `required` rule, so a missing star
        // costs no request.
        if (rating === null) {
            setPesanLokal('Pilih rating terlebih dahulu.');

            return;
        }

        setPesanLokal(null);

        kirimUlasan.mutate({
            rating,
            ...(komunikasi === null ? {} : { rating_komunikasi: komunikasi }),
            ...(akurasi === null ? {} : { rating_akurasi: akurasi }),
            ...(isi.trim() === '' ? {} : { isi: isi.trim() }),
            is_anonim: anonim,
        });
    };

    return (
        <Card data-slot="form-ulasan">
            <CardHeader>
                <CardTitle className="text-base">Tulis ulasan</CardTitle>

                <CardDescription>
                    Ulasan berlaku untuk satu konsultasi dan tidak dapat diubah
                    setelah dikirim.
                </CardDescription>
            </CardHeader>

            <CardContent>
                <form
                    noValidate
                    className="flex flex-col gap-6"
                    onSubmit={kirim}
                >
                    <PilihBintang
                        slot="pilih-bintang-rating"
                        name="rating"
                        legend="Rating keseluruhan"
                        hint="Wajib. Pilih 1 sampai 5 bintang."
                        value={rating}
                        onChange={(next) => {
                            setRating(next);
                            setPesanLokal(null);
                        }}
                        error={pesanLokal}
                    />

                    <PilihBintang
                        slot="pilih-bintang-komunikasi"
                        name="rating_komunikasi"
                        legend="Komunikasi"
                        value={komunikasi}
                        onChange={setKomunikasi}
                        opsional
                    />

                    <PilihBintang
                        slot="pilih-bintang-akurasi"
                        name="rating_akurasi"
                        legend="Akurasi"
                        value={akurasi}
                        onChange={setAkurasi}
                        opsional
                    />

                    <div className="flex flex-col gap-2">
                        <Label htmlFor="ulasan-isi" className="text-base">
                            Ulasan (opsional)
                        </Label>

                        <Textarea
                            id="ulasan-isi"
                            name="isi"
                            value={isi}
                            maxLength={ISI_MAKS}
                            placeholder="Ceritakan pengalaman Anda selama konsultasi."
                            className="min-h-28 text-base md:text-base"
                            onChange={(event) => {
                                setIsi(event.target.value);
                            }}
                        />

                        <p
                            className="text-muted-foreground text-sm"
                            aria-live="polite"
                        >
                            <span className="tabular-nums">{isi.length}</span>/
                            {ISI_MAKS} karakter
                        </p>
                    </div>

                    <div className="flex items-start gap-3">
                        <Checkbox
                            id="ulasan-anonim"
                            checked={anonim}
                            className="mt-1 size-5"
                            onCheckedChange={(nilai) => {
                                setAnonim(nilai === true);
                            }}
                        />

                        <div className="flex flex-col gap-0.5">
                            <Label htmlFor="ulasan-anonim" className="text-base">
                                Tampilkan ulasan sebagai anonim
                            </Label>

                            <p className="text-muted-foreground text-sm">
                                Bila dicentang, ulasan tampil sebagai “Pasien”.
                                Bila tidak, nama Anda ditampilkan dalam bentuk
                                disamarkan.
                            </p>
                        </div>
                    </div>

                    {pesanGalat.length === 0 ? null : (
                        <Alert variant="destructive" data-slot="ulasan-galat">
                            <AlertCircle aria-hidden />

                            <AlertTitle>Ulasan belum dapat dikirim.</AlertTitle>

                            <AlertDescription>
                                <ul className="list-disc pl-4">
                                    {pesanGalat.map((pesan) => (
                                        <li key={pesan}>{pesan}</li>
                                    ))}
                                </ul>
                            </AlertDescription>
                        </Alert>
                    )}

                    {online ? null : (
                        <p className="text-muted-foreground text-sm">
                            Anda sedang offline. Ulasan tidak dikirim sampai
                            koneksi kembali.
                        </p>
                    )}

                    <div className="flex flex-wrap items-center gap-3">
                        <Button
                            type="submit"
                            className="min-h-11"
                            aria-disabled={!online}
                            disabled={kirimUlasan.isPending}
                        >
                            {kirimUlasan.isPending ? (
                                <Loader2 aria-hidden className="animate-spin" />
                            ) : (
                                <Send aria-hidden />
                            )}

                            Kirim ulasan
                        </Button>

                        <Button
                            asChild
                            type="button"
                            variant="outline"
                            className="min-h-11"
                        >
                            <Link to={`/konsultasi/${String(konsultasi.id)}`}>
                                Batal
                            </Link>
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>
    );
}
