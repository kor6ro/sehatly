import { useState } from 'react';
import { useNavigate } from 'react-router';
import { useMutation, useQuery } from '@tanstack/react-query';
import { toast } from 'sonner';
import {
    buatPenggunaMutation,
    type BuatPenggunaInput,
    type TipeBuatPengguna,
} from '@/lib/api/admin';
import { ApiError } from '@/lib/http';
import { meOptions } from '@/lib/api/me';
import { AdminGate } from '@/portals/admin/features/admin/admin-gate';
import { PageHeader } from '@/shared/components/layout/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

/**
 * Create one staff or patient account.
 *
 * ## The `admin` option is hidden, not disabled, for an `admin` actor
 *
 * `POST /admin/pengguna` refuses a non-superadmin who tries to mint an
 * `admin` with 403 - the guard is the server's and stays there. Offering the
 * row anyway would turn every submit into a dead end the form could have
 * predicted, so the option exists only when `GET /me` says the caller is a
 * `superadmin`. An `admin` who needs one asks a superadmin, which is what the
 * 403 means.
 *
 * ## No `email` field
 *
 * `BuatPenggunaRequest` accepts `nama_lengkap`, `no_telepon`, `password` and
 * `tipe` - nothing else. An email column exists on `users`, but nothing in
 * this round reads or writes it, so a field for it would be a promise the
 * backend does not keep.
 */
const TIPE_BUAT: ReadonlyArray<{ nilai: TipeBuatPengguna; label: string }> = [
    { nilai: 'apoteker', label: 'Apoteker' },
    { nilai: 'kurir', label: 'Kurir' },
    { nilai: 'marketing', label: 'Marketing' },
    { nilai: 'admin', label: 'Admin' },
];

export function AdminPenggunaBuatPage() {
    return (
        <AdminGate>
            <AdminPenggunaBuatContent />
        </AdminGate>
    );
}

function AdminPenggunaBuatContent() {
    const navigate = useNavigate();
    const me = useQuery(meOptions());

    /**
     * `superadmin` is the only account that may mint an `admin`, so it is the
     * only one that is shown the row. The query is the shell's own cached
     * `GET /me`, so this costs no extra request, and while it is in flight the
     * conservative list (three, without `admin`) is what renders - a slow
     * response never flashes an option that would 403.
     */
    const bolehBuatAdmin = me.data?.data.user.tipe === 'superadmin';
    const pilihanTipe = bolehBuatAdmin
        ? TIPE_BUAT
        : TIPE_BUAT.filter((tipe) => tipe.nilai !== 'admin');

    const [form, setForm] = useState({
        nama_lengkap: '',
        no_telepon: '',
        password: '',
        tipe: '' as TipeBuatPengguna | '',
    });

    const [errors, setErrors] = useState<Record<string, string[]>>({});

    const buat = useMutation({
        ...buatPenggunaMutation(),
        onSuccess: (result) => {
            toast.success(
                `Akun ${result.data.pengguna.nama_lengkap} berhasil dibuat.`,
            );
            // Land on the DIRECTORY OF THE TYPE JUST CREATED, not on the old
            // `staf` umbrella: the sidebar is split per role now, and the new
            // account belongs to exactly one of them. `?tipe=` the server's
            // own filter vocabulary, so the list arrives already filtered to
            // the row the operator just wrote.
            void navigate(`/admin/pengguna?tipe=${result.data.pengguna.tipe}`);
        },
        onError: (error) => {
            if (error instanceof ApiError && error.isValidation) {
                setErrors(error.errors);
                return;
            }
            toast.error(
                error instanceof Error ? error.message : 'Gagal membuat akun.',
            );
        },
    });

    const set = (key: string, value: string): void => {
        setForm((prev) => ({ ...prev, [key]: value }));
        setErrors((prev) => ({ ...prev, [key]: [] }));
    };

    const fieldError = (key: string): string | undefined => errors[key]?.[0];

    const handleSubmit = (e: React.FormEvent): void => {
        e.preventDefault();
        setErrors({});

        if (!form.tipe) return;

        const input: BuatPenggunaInput = {
            nama_lengkap: form.nama_lengkap,
            no_telepon: form.no_telepon,
            password: form.password,
            tipe: form.tipe,
        };

        buat.mutate(input);
    };

    return (
        <>
            <PageHeader
                title="Buat akun"
                description="Akun baru langsung aktif dan nomornya terverifikasi - peserta bisa masuk seketika."
            />

            <form onSubmit={handleSubmit} className="flex max-w-xl flex-col gap-5">
                <fieldset className="flex flex-col gap-4">
                    <legend className="text-sm font-semibold">Identitas akun</legend>

                    <Field label="Nama lengkap" error={fieldError('nama_lengkap')}>
                        <Input
                            value={form.nama_lengkap}
                            autoComplete="off"
                            onChange={(e) => {
                                set('nama_lengkap', e.target.value);
                            }}
                        />
                    </Field>

                    <Field label="Nomor telepon" error={fieldError('no_telepon')}>
                        <Input
                            value={form.no_telepon}
                            autoComplete="off"
                            inputMode="tel"
                            onChange={(e) => {
                                set('no_telepon', e.target.value);
                            }}
                        />
                    </Field>

                    <Field label="Kata sandi awal" error={fieldError('password')}>
                        <Input
                            type="password"
                            value={form.password}
                            autoComplete="new-password"
                            onChange={(e) => {
                                set('password', e.target.value);
                            }}
                        />
                    </Field>
                </fieldset>

                <fieldset className="flex flex-col gap-4">
                    <legend className="text-sm font-semibold">Jenis akun</legend>

                    <Field label="Tipe" error={fieldError('tipe')}>
                        <Select
                            value={form.tipe}
                            onValueChange={(v) => {
                                set('tipe', v);
                            }}
                        >
                            <SelectTrigger>
                                <SelectValue placeholder="Pilih tipe" />
                            </SelectTrigger>

                            <SelectContent>
                                {pilihanTipe.map((tipe) => (
                                    <SelectItem key={tipe.nilai} value={tipe.nilai}>
                                        {tipe.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </Field>

                    {/**
                     * The door this form does NOT open, stated where the
                     * missing options would otherwise be looked for. The
                     * dropdown is staff-only by the server's own
                     * `TIPE_BUAT`, so without this a reader would search it
                     * for "Pasien" and "Dokter" and conclude the feature is
                     * broken rather than split across three doors.
                     */}
                    <p className="text-muted-foreground text-xs">
                        Hanya akun staf: apoteker, kurir, marketing, dan
                        admin (khusus superadmin). Pasien mendaftar sendiri
                        lewat aplikasi, dokter dibuat lewat menu Dokter.
                    </p>
                </fieldset>

                <div className="flex gap-3">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => {
                            void navigate('/admin/pengguna');
                        }}
                    >
                        Batal
                    </Button>

                    <Button type="submit" data-testid="admin-tulis" disabled={buat.isPending}>
                        Buat akun
                    </Button>
                </div>
            </form>
        </>
    );
}

/**
 * The same local `Field` `admin-dokter-buat-page` uses: a label over a control
 * with the server's 422 message under it. The shared `Field` in
 * `shared/components/form/field.tsx` generates its own `id` and wires it
 * through context - a better instrument for a form that needs it, and a
 * heavier one than this two-field screen requires.
 */
function Field({
    label,
    error,
    children,
}: {
    label: string;
    error?: string;
    children: React.ReactNode;
}) {
    return (
        <div className="flex flex-col gap-1.5">
            <Label>{label}</Label>
            {children}
            {error ? <p className="text-destructive text-xs">{error}</p> : null}
        </div>
    );
}
