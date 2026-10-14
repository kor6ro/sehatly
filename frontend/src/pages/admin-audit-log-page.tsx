import { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import {
    Download,
    Eye,
    LogIn,
    LogOut,
    Pencil,
    Plus,
    RefreshCw,
    Trash2,
    Upload,
    type LucideIcon,
} from 'lucide-react';
import {
    adminAuditOptions,
    type AdminAuditFilter,
    type AdminAuditLog,
    type AksiAudit,
} from '@/lib/api/admin';
import { useDocumentTitle } from '@/hooks/use-document-title';
import { formatWaktuZona } from '@/lib/waktu';
import { AdminErrorState, AdminGate } from '@/features/admin/admin-gate';
import { labelAksi } from '@/features/admin/format-admin';
import { PageHeader } from '@/components/layout/page-header';
import { Pagination } from '@/components/layout/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Field, FieldInput, FieldSelect } from '@/components/form/field';
import { SelectItem } from '@/components/ui/select';
import { EmptyState } from '@/components/states/empty-state';
import { SkeletonRows } from '@/components/states/loading-state';

const AKSI: ReadonlyArray<AksiAudit> = [
    'create',
    'read',
    'update',
    'delete',
    'login',
    'logout',
    'download',
    'export',
];

const IKON_AKSI: Readonly<Record<AksiAudit, LucideIcon>> = {
    create: Plus,
    read: Eye,
    update: Pencil,
    delete: Trash2,
    login: LogIn,
    logout: LogOut,
    download: Download,
    export: Upload,
};

const PER_HALAMAN = 15;

function nilaiTampil(value: unknown): string {
    if (value === null || value === undefined) {
        return '—';
    }

    if (typeof value === 'string') {
        return value;
    }

    if (typeof value === 'number' || typeof value === 'boolean') {
        return String(value);
    }

    return JSON.stringify(value);
}

/**
 * The masked diff of one audit row.
 *
 * `data_lama`/`data_baru` are already the output of `AuditColumnPolicy` at write
 * time - denied columns were dropped and `nomor_str`, NIK, phone and email were
 * masked before the row existed. This renders the stored keys and values
 * verbatim: there is no client-side masker and no column is hidden, because
 * re-deciding what is sensitive here would be a second, driftable policy over
 * data the server already decided. Values wrap rather than truncate, so a
 * masked credential is readable in full.
 */
function RingkasanPerubahan({ baris }: { baris: AdminAuditLog }) {
    const lama = baris.data_lama ?? {};
    const baru = baris.data_baru ?? {};

    const kunci = useMemo(
        () =>
            [...new Set([...Object.keys(lama), ...Object.keys(baru)])].sort((a, b) =>
                a.localeCompare(b),
            ),
        [lama, baru],
    );

    if (kunci.length === 0) {
        return <span className="text-muted-foreground text-sm">—</span>;
    }

    return (
        <ul className="flex flex-col gap-1 text-sm">
            {kunci.map((key) => (
                <li key={key} className="break-words">
                    <span className="font-medium">{key}</span>:{' '}
                    <span className="text-muted-foreground line-through">
                        {nilaiTampil(lama[key])}
                    </span>{' '}
                    → <span>{nilaiTampil(baru[key])}</span>
                </li>
            ))}
        </ul>
    );
}

/**
 * `/admin/audit-log` - the read-only trail.
 *
 * ## Zero write controls, and that is the API's shape
 *
 * `audit_log` has no update timestamp and the route table registers exactly one
 * verb on `/admin/audit-log`, a GET. There is no edit, delete or export
 * control here because none exists to call - the screen's own note states it.
 *
 * ## The actor is a historical id, not a name
 *
 * `AdminAuditLogResource` deliberately does not join a name: `user_id` is a
 * bare column so the row survives the account's deletion, and a joined name
 * would be a second copy of personal data in a compliance surface. The filter
 * is `aktor_user_id` for the same reason, and the row prints the id it has.
 */
export function AdminAuditLogPage() {
    useDocumentTitle('Jejak audit');

    return (
        <AdminGate>
            <AdminAuditContent />
        </AdminGate>
    );
}

function AdminAuditContent() {
    const [filter, setFilter] = useState<AdminAuditFilter>({
        page: 1,
        per_page: PER_HALAMAN,
    });

    const [aksi, setAksi] = useState('semua');
    const [tabel, setTabel] = useState('');
    const [aktor, setAktor] = useState('');
    const [dari, setDari] = useState('');
    const [sampai, setSampai] = useState('');

    const audit = useQuery(adminAuditOptions(filter));
    const rows = audit.data?.data.audit ?? [];

    const adaFilter =
        filter.aksi !== undefined ||
        filter.tabel_target !== undefined ||
        filter.aktor_user_id !== undefined ||
        filter.dari !== undefined ||
        filter.sampai !== undefined;

    const terapkan = (halaman: number): void => {
        setFilter({
            page: halaman,
            per_page: PER_HALAMAN,
            ...(aksi === 'semua' ? {} : { aksi: aksi as AksiAudit }),
            ...(tabel.trim() === '' ? {} : { tabel_target: tabel.trim() }),
            ...(aktor.trim() === '' ? {} : { aktor_user_id: Number(aktor) }),
            ...(dari === '' ? {} : { dari }),
            ...(sampai === '' ? {} : { sampai }),
        });
    };

    const reset = (): void => {
        setAksi('semua');
        setTabel('');
        setAktor('');
        setDari('');
        setSampai('');
        setFilter({ page: 1, per_page: PER_HALAMAN });
    };

    return (
        <>
            <PageHeader
                title="Jejak audit"
                description="Riwayat perubahan data, hanya-baca."
                action={
                    <div data-testid="admin-aksi">
                        <Button
                            type="button"
                            variant="outline"
                            className="h-11"
                            onClick={() => {
                                void audit.refetch();
                            }}
                        >
                            <RefreshCw aria-hidden />
                            Muat ulang
                        </Button>
                    </div>
                }
            />

            <p className="text-muted-foreground text-sm">
                Jejak audit bersifat hanya-baca dan tidak dapat diubah atau dihapus.
                Ringkasan perubahan sudah menyamarkan data sensitif. Kebijakan retensi
                belum ditetapkan pada versi ini.
            </p>

            <form
                className="flex flex-col gap-3"
                onSubmit={(event) => {
                    event.preventDefault();
                    terapkan(1);
                }}
            >
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <Field label="Aksi">
                        <FieldSelect value={aksi} onValueChange={setAksi}>
                            <SelectItem value="semua">Semua aksi</SelectItem>

                            {AKSI.map((item) => (
                                <SelectItem key={item} value={item}>
                                    {labelAksi(item)}
                                </SelectItem>
                            ))}
                        </FieldSelect>
                    </Field>

                    <Field label="Tabel">
                        <FieldInput
                            value={tabel}
                            autoComplete="off"
                            placeholder="mis. dokter_jadwal"
                            onChange={(event) => {
                                setTabel(event.target.value);
                            }}
                        />
                    </Field>

                    <Field label="Pelaku (id pengguna)">
                        <FieldInput
                            type="number"
                            min={1}
                            value={aktor}
                            inputMode="numeric"
                            onChange={(event) => {
                                setAktor(event.target.value);
                            }}
                        />
                    </Field>

                    <Field label="Dari tanggal">
                        <FieldInput
                            type="date"
                            value={dari}
                            onChange={(event) => {
                                setDari(event.target.value);
                            }}
                        />
                    </Field>

                    <Field label="Sampai tanggal">
                        <FieldInput
                            type="date"
                            value={sampai}
                            onChange={(event) => {
                                setSampai(event.target.value);
                            }}
                        />
                    </Field>
                </div>

                <div data-testid="admin-aksi" className="flex flex-wrap gap-2">
                    <Button type="submit" variant="secondary" className="h-11">
                        Terapkan filter
                    </Button>

                    {adaFilter ? (
                        <Button
                            type="button"
                            variant="ghost"
                            className="h-11"
                            onClick={reset}
                        >
                            Hapus filter
                        </Button>
                    ) : null}
                </div>
            </form>

            {audit.isPending ? (
                <SkeletonRows rows={6} />
            ) : audit.isError ? (
                <AdminErrorState
                    title="Gagal memuat jejak audit."
                    error={audit.error}
                    onRetry={() => {
                        void audit.refetch();
                    }}
                />
            ) : rows.length === 0 ? (
                <EmptyState
                    title="Belum ada aktivitas pada filter ini."
                    description="Ubah atau hapus filter untuk melihat aktivitas lain."
                    action={
                        <Button
                            type="button"
                            variant="outline"
                            className="h-11"
                            onClick={reset}
                        >
                            Hapus filter
                        </Button>
                    }
                />
            ) : (
                <>
                    <ul role="list" className="flex flex-col gap-3">
                        {rows.map((baris) => {
                            const Ikon = IKON_AKSI[baris.aksi];

                            return (
                                <li
                                    key={baris.id}
                                    data-slot="admin-audit-row"
                                    className="border-border flex flex-col gap-2 rounded-lg border p-4"
                                >
                                    <div className="flex flex-wrap items-center gap-2">
                                        <Badge
                                            variant="outline"
                                            data-aksi={baris.aksi}
                                            className="gap-1 font-normal"
                                        >
                                            <Ikon aria-hidden className="size-3.5" />
                                            {labelAksi(baris.aksi)}
                                        </Badge>

                                        <span className="text-muted-foreground text-sm tabular-nums">
                                            {formatWaktuZona(baris.dibuat_at)}
                                        </span>

                                        <span className="text-sm">
                                            Pelaku:{' '}
                                            <span className="tabular-nums">
                                                {baris.user_id === null
                                                    ? 'Sistem'
                                                    : `Pengguna #${baris.user_id}`}
                                            </span>
                                        </span>
                                    </div>

                                    <p className="text-base">
                                        <span className="font-medium">
                                            {baris.tabel_target}
                                        </span>

                                        {baris.record_id === null ? null : (
                                            <span className="tabular-nums">
                                                {' '}
                                                #{baris.record_id}
                                            </span>
                                        )}
                                    </p>

                                    <div>
                                        <p className="text-muted-foreground text-sm">
                                            Ringkasan perubahan (data sensitif
                                            disamarkan)
                                        </p>

                                        <RingkasanPerubahan baris={baris} />
                                    </div>
                                </li>
                            );
                        })}
                    </ul>

                    <Pagination
                        meta={audit.data?.meta}
                        onPageChange={(halaman) => {
                            terapkan(halaman);
                        }}
                    />
                </>
            )}
        </>
    );
}
