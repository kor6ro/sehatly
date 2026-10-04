import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ImageMinus, Pencil, Plus, RefreshCw, Trash2, Upload } from 'lucide-react';
import { toast } from 'sonner';
import { useDocumentTitle } from '@/hooks/use-document-title';
import {
    adminHeroOptions,
    adminHeroQueryKey,
    heroQueryKey,
    hapusHero,
    lepasHeroGambar,
    simpanHero,
    ubahHero,
    unggahHeroGambar,
    type HeroSlide,
    type InputHero,
    type StatusHero,
} from '@/lib/api/hero';
import { formatWaktuZona } from '@/lib/waktu';
import { AdminErrorState, AdminGate } from '@/features/admin/admin-gate';
import { pesanField, pesanRingkas } from '@/features/admin/pesan';
import { PageHeader } from '@/components/layout/page-header';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Field, FieldInput, FieldSelect, FieldTextarea, FormErrorSummary } from '@/components/form/field';
import { SelectItem } from '@/components/ui/select';
import { EmptyState } from '@/components/states/empty-state';
import { SkeletonRows } from '@/components/states/loading-state';

/**
 * `/admin/hero` - the front page's carousel, edited without a deploy.
 *
 * ## Why this screen exists at all
 *
 * The landing strip used to be three objects in `features/landing/data.ts`, so changing
 * a banner meant a commit, a build and a release. `hero_slides` moves that decision to
 * an `admin`: the copy, the position, the publication window and the photograph are
 * rows, and this is the only screen that writes them. `HeroCarousel` keeps
 * `SLIDE_HERO` as its fallback precisely so that "the table is empty" and "the API is
 * down" both render a real front page instead of an empty band.
 *
 * ## What is deliberately NOT here
 *
 * - **No drag-to-reorder and no bulk endpoint.** Position is `urutan` on the row, and
 *   the server's `PUT` is the way it changes. A strip whose order can only be changed
 *   one number at a time is slower than a drag, and it is honest: there is no
 *   reorder endpoint to optimise for.
 * - **No audit note.** These writes produce no `audit_log` row, because the trail is
 *   written by `AuditObserver` on Eloquent events and this module has no model by
 *   contract. `HeroService` states the consequence; this screen does not promise a
 *   trail it cannot keep, and the delete dialog says the delete is permanent.
 * - **No pagination.** The server answers every row, drafts included, because an
 *   operator reordering a strip has to be able to see the whole strip.
 *
 * ## The five-slide cap is the server's, and the client shows it
 *
 * `HeroService::MAKS_TAYANG` refuses a sixth published slide with a 422 naming
 * `status`. That refusal arrives here as a field error on the Status select - the only
 * place in this form where an error cannot be fixed by editing a different field. The
 * counter in the header (`n / 5`) exists so the operator meets the ceiling before the
 * request rather than after it; it is derived from the same list the toggle writes.
 */

/** The server's clock for a window bound: `config/app.php` pins `timezone` to `UTC`. */
function tampilBatasWaktu(nilai: string | null): string {
    if (nilai === null || nilai === '') {
        return '-';
    }

    const iso = nilai.includes('T') ? nilai : nilai.replace(' ', 'T');

    return formatWaktuZona(`${iso}Z`);
}

/** What the edit form holds. Every field is a string: an empty one means "none". */
type Form = {
    urutan: string;
    eyebrow: string;
    judul: string;
    deskripsi: string;
    cta_label: string;
    cta_target: string;
    status: StatusHero;
    mulai_tayang: string;
    selesai_tayang: string;
};

const FORM_KOSONG: Form = {
    urutan: '',
    eyebrow: '',
    judul: '',
    deskripsi: '',
    cta_label: '',
    cta_target: '/',
    status: 'draf',
    mulai_tayang: '',
    selesai_tayang: '',
};

/** `Y-m-d H:i:s` -> the `datetime-local` spelling, and `null` -> an empty field. */
function keInputWaktu(nilai: string | null): string {
    return nilai === null ? '' : nilai.slice(0, 16).replace(' ', 'T');
}

/** The inverse: a blank field is "no bound", not midnight. */
function keDataWaktu(nilai: string): string | null {
    if (nilai === '') {
        return null;
    }

    return `${nilai.replace('T', ' ')}:00`;
}

function dariHero(baris: HeroSlide): Form {
    return {
        urutan: String(baris.urutan),
        eyebrow: baris.eyebrow ?? '',
        judul: baris.judul,
        deskripsi: baris.deskripsi,
        cta_label: baris.cta_label,
        cta_target: baris.cta_target,
        status: baris.status,
        mulai_tayang: keInputWaktu(baris.mulai_tayang),
        selesai_tayang: keInputWaktu(baris.selesai_tayang),
    };
}

/**
 * The form's payload.
 *
 * `urutan` is dropped entirely when its field is blank so the server takes the next
 * free position - sending `''` would be a 422 on an integer column, and sending `0`
 * would put every new slide at the front of the strip.
 */
function keInput(form: Form): InputHero {
    const kosong = (nilai: string): boolean => nilai.trim() === '';

    return {
        urutan: kosong(form.urutan) ? undefined : Number(form.urutan),
        eyebrow: kosong(form.eyebrow) ? null : form.eyebrow.trim(),
        judul: form.judul.trim(),
        deskripsi: form.deskripsi.trim(),
        cta_label: form.cta_label.trim(),
        cta_target: form.cta_target.trim(),
        status: form.status,
        mulai_tayang: keDataWaktu(form.mulai_tayang),
        selesai_tayang: keDataWaktu(form.selesai_tayang),
    };
}

/** The one-field message for `field`, shaped for `Field`'s `errors` prop. */
function galat(error: unknown, field: string): string[] {
    const pesan = pesanField(error, field);

    return pesan === null ? [] : [pesan];
}

export function AdminHeroPage() {
    useDocumentTitle('Carousel beranda');

    return (
        <AdminGate>
            <AdminHeroContent />
        </AdminGate>
    );
}

function AdminHeroContent() {
    const queryClient = useQueryClient();

    const hero = useQuery(adminHeroOptions());
    const rows = hero.data?.data.hero ?? [];

    const [form, setForm] = useState<Form>(FORM_KOSONG);
    const [editId, setEditId] = useState<number | null>(null);
    const [formTerbuka, setFormTerbuka] = useState(false);
    const [dihapus, setDihapus] = useState<HeroSlide | null>(null);
    const [berkas, setBerkas] = useState<File | null>(null);
    const [alt, setAlt] = useState('');

    /**
     * The slide being edited, READ BACK from the list rather than held as a snapshot.
     * An upload or a status toggle answers with the fresh row, but the dialog's
     * thumbnail has to follow too - and a copy captured when the dialog opened would
     * keep showing the file the operator just replaced.
     */
    const kini = editId === null ? null : (rows.find((baris) => baris.id === editId) ?? null);

    const segarkan = (): void => {
        void queryClient.invalidateQueries({ queryKey: adminHeroQueryKey });
        void queryClient.invalidateQueries({ queryKey: heroQueryKey });
    };

    const tutupForm = (): void => {
        setFormTerbuka(false);
        setEditId(null);
        setForm(FORM_KOSONG);
        setBerkas(null);
        setAlt('');
    };

    const bukaBaru = (): void => {
        tutupForm();
        setFormTerbuka(true);
    };

    const bukaUbah = (baris: HeroSlide): void => {
        setForm(dariHero(baris));
        setEditId(baris.id);
        setBerkas(null);
        setAlt('');
        setFormTerbuka(true);
    };

    const simpan = useMutation({
        mutationFn: (input: InputHero) =>
            editId === null ? simpanHero(input) : ubahHero(editId, input),
        onSuccess: () => {
            segarkan();
            tutupForm();
            toast.success(editId === null ? 'Slide ditambahkan sebagai draf.' : 'Slide diperbarui.');
        },
        onError: (error) => {
            toast.error(pesanRingkas(error) ?? 'Gagal menyimpan slide.');
        },
    });

    const toggle = useMutation({
        mutationFn: (baris: HeroSlide) =>
            ubahHero(baris.id, { status: baris.status === 'tayang' ? 'draf' : 'tayang' }),
        onSuccess: (hasil) => {
            segarkan();
            toast.success(
                hasil.data.hero.status === 'tayang'
                    ? 'Slide tayang di beranda.'
                    : 'Slide dijadikan draf dan disembunyikan.',
            );
        },
        onError: (error) => {
            toast.error(pesanRingkas(error) ?? 'Gagal mengubah status.');
        },
    });

    const buang = useMutation({
        mutationFn: (id: number) => hapusHero(id),
        onSuccess: () => {
            segarkan();
            setDihapus(null);
            toast.success('Slide dihapus, termasuk berkas gambarnya.');
        },
        onError: (error) => {
            setDihapus(null);
            toast.error(pesanRingkas(error) ?? 'Gagal menghapus slide.');
        },
    });

    const gambar = useMutation({
        mutationFn: (input: { id: number; file: File; alt: string }) =>
            unggahHeroGambar(input.id, input.file, input.alt),
        onSuccess: () => {
            segarkan();
            setBerkas(null);
            setAlt('');
            toast.success('Gambar terpasang.');
        },
        onError: (error) => {
            toast.error(pesanRingkas(error) ?? 'Gagal mengunggah gambar.');
        },
    });

    const lepas = useMutation({
        mutationFn: (id: number) => lepasHeroGambar(id),
        onSuccess: () => {
            segarkan();
            toast.success('Gambar dilepas. Slide tetap memakai salinan yang sama.');
        },
        onError: (error) => {
            toast.error(pesanRingkas(error) ?? 'Gagal melepas gambar.');
        },
    });

    if (hero.isPending) {
        return <SkeletonRows rows={5} />;
    }

    if (hero.isError) {
        return (
            <AdminErrorState
                error={hero.error}
                title="Gagal memuat slide"
                onRetry={() => void hero.refetch()}
            />
        );
    }

    const tayang = rows.filter((baris) => baris.status === 'tayang').length;

    return (
        <>
            <PageHeader
                title="Carousel beranda"
                description={`${rows.length} slide, ${tayang} di antaranya tayang. Ditulis lewat GET/POST/PUT/DELETE /admin/hero dan tampil di halaman utama lewat GET /hero.`}
                action={
                    <div data-testid="admin-hero-aksi">
                        <Button
                            type="button"
                            variant="outline"
                            className="h-11"
                            onClick={() => void hero.refetch()}
                        >
                            <RefreshCw aria-hidden />
                            Muat ulang
                        </Button>

                        <Button
                            type="button"
                            className="h-11"
                            data-testid="admin-hero-baru"
                            onClick={bukaBaru}
                        >
                            <Plus aria-hidden />
                            Slide baru
                        </Button>
                    </div>
                }
            />

            <p className="text-muted-foreground text-sm">
                Maksimal 5 slide boleh tayang bersamaan. Menayangkan slide keenam
                ditolak server dengan pesan di kolom Status, dan perubahan tidak
                tercatat di jejak audit - halaman ini menulis baris biasa, bukan lewat
                model Eloquent.
            </p>

            {rows.length === 0 ? (
                <EmptyState
                    title="Belum ada slide"
                    description="Halaman utama sedang memakai tiga slide bawaan dari berkas komponen. Tambahkan slide pertama untuk menggantinya."
                    action={
                        <Button type="button" onClick={bukaBaru}>
                            <Plus aria-hidden />
                            Tambah slide pertama
                        </Button>
                    }
                />
            ) : (
                <ul
                    data-testid="admin-hero-daftar"
                    className="flex flex-col gap-3"
                >
                    {rows.map((baris) => (
                        <li
                            key={baris.id}
                            className="flex flex-col gap-4 rounded-lg border p-4 md:flex-row md:items-start"
                        >
                            <div className="bg-muted text-muted-foreground flex h-20 w-36 shrink-0 items-center justify-center overflow-hidden rounded-md text-center text-xs">
                                {baris.gambar === null ? (
                                    <span className="px-2">Tanpa gambar</span>
                                ) : (
                                    <img
                                        src={baris.gambar}
                                        alt=""
                                        className="size-full object-cover"
                                    />
                                )}
                            </div>

                            <div className="min-w-0 flex-1 space-y-1.5">
                                <div className="flex flex-wrap items-center gap-2">
                                    <Badge variant="outline">urutan {baris.urutan}</Badge>

                                    <Badge
                                        variant={
                                            baris.status === 'tayang'
                                                ? 'successSubtle'
                                                : 'secondary'
                                        }
                                    >
                                        {baris.status === 'tayang' ? 'Tayang' : 'Draf'}
                                    </Badge>

                                    {baris.status === 'tayang' && !baris.tayang_aktif ? (
                                        <Badge variant="warningSubtle">
                                            Di luar jendela tayang
                                        </Badge>
                                    ) : null}
                                </div>

                                <p className="text-sm font-medium break-words">
                                    {baris.eyebrow === null || baris.eyebrow === '' ? null : (
                                        <span className="text-muted-foreground mr-2 text-xs uppercase">
                                            {baris.eyebrow}
                                        </span>
                                    )}
                                    {baris.judul}
                                </p>

                                <p className="text-muted-foreground line-clamp-2 text-sm">
                                    {baris.deskripsi}
                                </p>

                                <p className="text-muted-foreground text-xs">
                                    Tombol <span className="font-medium">{baris.cta_label}</span>{' '}
                                    menuju <code className="break-all">{baris.cta_target}</code>
                                </p>

                                <p className="text-muted-foreground text-xs">
                                    Jendela: {tampilBatasWaktu(baris.mulai_tayang)} →{' '}
                                    {tampilBatasWaktu(baris.selesai_tayang)}
                                </p>
                            </div>

                            <div className="flex shrink-0 flex-wrap items-start gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => bukaUbah(baris)}
                                >
                                    <Pencil aria-hidden />
                                    Ubah
                                </Button>

                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    disabled={toggle.isPending}
                                    onClick={() => toggle.mutate(baris)}
                                >
                                    {baris.status === 'tayang' ? 'Jadikan draf' : 'Tayangkan'}
                                </Button>

                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setDihapus(baris)}
                                >
                                    <Trash2 aria-hidden />
                                    Hapus
                                </Button>
                            </div>
                        </li>
                    ))}
                </ul>
            )}

            <Dialog
                open={formTerbuka}
                onOpenChange={(terbuka) => {
                    if (!terbuka) {
                        tutupForm();
                    } else {
                        setFormTerbuka(true);
                    }
                }}
            >
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>
                            {editId === null ? 'Slide baru' : 'Ubah slide'}
                        </DialogTitle>

                        <DialogDescription>
                            Kolom bertanda wajib diisi. Tautan hanya boleh berupa path di
                            dalam aplikasi - alamat http(s) ditolak server supaya halaman
                            utama tidak bisa diarahkan ke situs lain.
                        </DialogDescription>
                    </DialogHeader>

                    <form
                        className="flex flex-col gap-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            simpan.mutate(keInput(form));
                        }}
                    >
                        <FormErrorSummary error={simpan.error} />

                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field
                                label="Judul"
                                required
                                className="sm:col-span-2"
                                errors={galat(simpan.error, 'judul')}
                            >
                                <FieldInput
                                    value={form.judul}
                                    maxLength={160}
                                    onChange={(event) =>
                                        setForm({ ...form, judul: event.target.value })
                                    }
                                />
                            </Field>

                            <Field
                                label="Deskripsi"
                                required
                                className="sm:col-span-2"
                                hint="Maksimal 400 karakter, tampil di bawah judul."
                                errors={galat(simpan.error, 'deskripsi')}
                            >
                                <FieldTextarea
                                    value={form.deskripsi}
                                    maxLength={400}
                                    rows={3}
                                    onChange={(event) =>
                                        setForm({ ...form, deskripsi: event.target.value })
                                    }
                                />
                            </Field>

                            <Field
                                label="Label kecil"
                                hint="Baris di atas judul. Kosongkan bila tidak dipakai."
                                errors={galat(simpan.error, 'eyebrow')}
                            >
                                <FieldInput
                                    value={form.eyebrow}
                                    maxLength={60}
                                    onChange={(event) =>
                                        setForm({ ...form, eyebrow: event.target.value })
                                    }
                                />
                            </Field>

                            <Field
                                label="Urutan"
                                hint="Kosongkan untuk menambahkan di akhir strip."
                                errors={galat(simpan.error, 'urutan')}
                            >
                                <FieldInput
                                    type="number"
                                    min={0}
                                    inputMode="numeric"
                                    value={form.urutan}
                                    onChange={(event) =>
                                        setForm({ ...form, urutan: event.target.value })
                                    }
                                />
                            </Field>

                            <Field
                                label="Label tombol"
                                required
                                errors={galat(simpan.error, 'cta_label')}
                            >
                                <FieldInput
                                    value={form.cta_label}
                                    maxLength={60}
                                    onChange={(event) =>
                                        setForm({ ...form, cta_label: event.target.value })
                                    }
                                />
                            </Field>

                            <Field
                                label="Tautan tombol"
                                required
                                hint="Path internal yang ada di aplikasi, contoh /dokter atau /konsultasi. Alamat http(s) ditolak server."
                                errors={galat(simpan.error, 'cta_target')}
                            >
                                <FieldInput
                                    value={form.cta_target}
                                    maxLength={120}
                                    onChange={(event) =>
                                        setForm({ ...form, cta_target: event.target.value })
                                    }
                                />
                            </Field>

                            <Field
                                label="Mulai tayang"
                                hint="Kosongkan untuk tanpa batas awal."
                                errors={galat(simpan.error, 'mulai_tayang')}
                            >
                                <FieldInput
                                    type="datetime-local"
                                    value={form.mulai_tayang}
                                    onChange={(event) =>
                                        setForm({ ...form, mulai_tayang: event.target.value })
                                    }
                                />
                            </Field>

                            <Field
                                label="Selesai tayang"
                                hint="Kosongkan untuk tanpa batas akhir."
                                errors={galat(simpan.error, 'selesai_tayang')}
                            >
                                <FieldInput
                                    type="datetime-local"
                                    value={form.selesai_tayang}
                                    onChange={(event) =>
                                        setForm({ ...form, selesai_tayang: event.target.value })
                                    }
                                />
                            </Field>

                            <Field
                                label="Status"
                                hint={`${tayang} slide sedang tayang dari maksimal 5.`}
                                errors={galat(simpan.error, 'status')}
                            >
                                <FieldSelect
                                    value={form.status}
                                    onValueChange={(nilai) =>
                                        setForm({
                                            ...form,
                                            status: nilai as StatusHero,
                                        })
                                    }
                                >
                                    <SelectItem value="draf">Draf</SelectItem>
                                    <SelectItem value="tayang">Tayang</SelectItem>
                                </FieldSelect>
                            </Field>
                        </div>

                        {kini === null ? null : (
                            <div className="flex flex-col gap-3 rounded-lg border p-4">
                                <p className="text-sm font-medium">Gambar</p>

                                {kini.gambar === null ? (
                                    <p className="text-muted-foreground text-sm">
                                        Belum ada gambar. Slide memakai gradiennya sendiri
                                        sampai satu diunggah.
                                    </p>
                                ) : (
                                    <img
                                        src={kini.gambar}
                                        alt={kini.gambar_alt ?? ''}
                                        className="h-32 w-full rounded-md object-cover"
                                    />
                                )}

                                <Field
                                    label="Berkas gambar"
                                    hint="JPG, PNG, WebP atau AVIF, maksimal 2048 KB. Berkas baru menggantikan dan menghapus yang lama."
                                    errors={galat(gambar.error, 'gambar')}
                                >
                                    <FieldInput
                                        type="file"
                                        accept="image/jpeg,image/png,image/webp,image/avif"
                                        onChange={(event) =>
                                            setBerkas(event.target.files?.[0] ?? null)
                                        }
                                    />
                                </Field>

                                <Field
                                    label="Teks alternatif"
                                    required
                                    hint="Wajib diisi bersama berkasnya: dibaca pembaca layar dan dipakai bila gambar gagal dimuat."
                                    errors={galat(gambar.error, 'gambar_alt')}
                                >
                                    <FieldInput
                                        value={alt}
                                        maxLength={160}
                                        onChange={(event) => setAlt(event.target.value)}
                                    />
                                </Field>

                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        type="button"
                                        size="sm"
                                        disabled={berkas === null || gambar.isPending}
                                        onClick={() => {
                                            if (berkas !== null && kini !== null) {
                                                gambar.mutate({
                                                    id: kini.id,
                                                    file: berkas,
                                                    alt: alt.trim(),
                                                });
                                            }
                                        }}
                                    >
                                        <Upload aria-hidden />
                                        Unggah
                                    </Button>

                                    {kini.gambar === null || lepas.isPending ? null : (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={() => lepas.mutate(kini.id)}
                                        >
                                            <ImageMinus aria-hidden />
                                            Lepas gambar
                                        </Button>
                                    )}
                                </div>
                            </div>
                        )}

                        <DialogFooter>
                            <DialogClose asChild>
                                <Button type="button" variant="outline" className="min-h-11">
                                    Batal
                                </Button>
                            </DialogClose>

                            <Button
                                type="submit"
                                className="min-h-11"
                                disabled={simpan.isPending}
                            >
                                {editId === null ? 'Simpan slide' : 'Simpan perubahan'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog
                open={dihapus !== null}
                onOpenChange={(terbuka) => {
                    if (!terbuka) {
                        setDihapus(null);
                    }
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            Hapus slide “{dihapus?.judul ?? ''}”?
                        </DialogTitle>

                        <DialogDescription>
                            Slide beserta berkas gambarnya dihapus permanen, dan halaman
                            utama langsung kembali memakai slide bawaan bila tidak ada
                            slide tayang tersisa. Tindakan ini tidak tercatat di jejak
                            audit.
                        </DialogDescription>
                    </DialogHeader>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            className="min-h-11"
                            onClick={() => setDihapus(null)}
                        >
                            Batal
                        </Button>

                        <Button
                            type="button"
                            variant="destructive"
                            className="min-h-11"
                            disabled={buang.isPending}
                            onClick={() => {
                                const target = dihapus;

                                if (target !== null) {
                                    buang.mutate(target.id);
                                }
                            }}
                        >
                            Hapus slide
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
