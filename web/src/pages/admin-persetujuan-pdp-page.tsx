import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { RefreshCw } from 'lucide-react';
import {
    adminPdpOptions,
    type AdminPdpFilter,
    type JenisPersetujuanPdp,
} from '@/lib/api/admin';
import { useDocumentTitle } from '@/hooks/use-document-title';
import { formatWaktuZona } from '@/lib/waktu';
import { AdminErrorState, AdminGate } from '@/features/admin/admin-gate';
import { labelJenisPdp } from '@/features/admin/format-admin';
import { PdpStatusBadge } from '@/features/pdp/pdp-status-badge';
import { PageHeader } from '@/components/layout/page-header';
import { Pagination } from '@/components/layout/pagination';
import { Button } from '@/components/ui/button';
import { Field, FieldInput, FieldSelect } from '@/components/form/field';
import { SelectItem } from '@/components/ui/select';
import { EmptyState } from '@/components/states/empty-state';
import { SkeletonRows } from '@/components/states/loading-state';

const JENIS: ReadonlyArray<JenisPersetujuanPdp> = [
    'syarat_ketentuan',
    'kebijakan_privasi',
    'berbagi_data_medis',
    'pemasaran',
    'komunikasi_tindak_lanjut',
];

const PER_HALAMAN = 15;

/**
 * `/admin/persetujuan-pdp` - the READ-ONLY consent ledger.
 *
 * ## No write control exists because a consent is the subject's own act
 *
 * `pdp.kelola` is a management code whose one consumer is this GET; the write
 * routes belong to the data subject (`POST /pdp/persetujuan`). An admin reading
 * the ledger to reconcile which consent exists against which document version
 * is the surface's purpose, and recording a consent on somebody's behalf is the
 * defect `routes/api.php`'s PDP docblock names. So the page filters, paginates
 * and renders - nothing else.
 *
 * ## What is deliberately absent
 *
 * The resource publishes no IP and no name: a ledger row names the record, not
 * the person. The row shows `user_id`, the document kind and version, the
 * decision and when it was recorded, in `id DESC` append order.
 */
export function AdminPersetujuanPdpPage() {
    useDocumentTitle('Persetujuan PDP');

    return (
        <AdminGate>
            <AdminPdpContent />
        </AdminGate>
    );
}

function AdminPdpContent() {
    const [filter, setFilter] = useState<AdminPdpFilter>({
        page: 1,
        per_page: PER_HALAMAN,
    });

    const [jenis, setJenis] = useState('semua');
    const [disetujui, setDisetujui] = useState('semua');
    const [userId, setUserId] = useState('');

    const pdp = useQuery(adminPdpOptions(filter));
    const rows = pdp.data?.data.persetujuan_pdp ?? [];

    const adaFilter =
        filter.jenis !== undefined ||
        filter.disetujui !== undefined ||
        filter.user_id !== undefined;

    const terapkan = (halaman: number): void => {
        setFilter({
            page: halaman,
            per_page: PER_HALAMAN,
            ...(jenis === 'semua'
                ? {}
                : { jenis: jenis as JenisPersetujuanPdp }),
            ...(disetujui === 'semua'
                ? {}
                : { disetujui: disetujui === 'ya' }),
            ...(userId.trim() === '' ? {} : { user_id: Number(userId) }),
        });
    };

    const reset = (): void => {
        setJenis('semua');
        setDisetujui('semua');
        setUserId('');
        setFilter({ page: 1, per_page: PER_HALAMAN });
    };

    return (
        <>
            <PageHeader
                title="Persetujuan PDP"
                description="Ledger persetujuan data pribadi, hanya-baca."
                action={
                    <div data-testid="admin-aksi">
                        <Button
                            type="button"
                            variant="outline"
                            className="h-11"
                            onClick={() => {
                                void pdp.refetch();
                            }}
                        >
                            <RefreshCw aria-hidden />
                            Muat ulang
                        </Button>
                    </div>
                }
            />

            <p className="text-muted-foreground text-sm">
                Catatan persetujuan bersifat hanya-baca. Admin tidak mencatat atau
                menarik persetujuan atas nama pengguna.
            </p>

            <form
                className="flex flex-col gap-3"
                onSubmit={(event) => {
                    event.preventDefault();
                    terapkan(1);
                }}
            >
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <Field label="Jenis persetujuan">
                        <FieldSelect value={jenis} onValueChange={setJenis}>
                            <SelectItem value="semua">Semua jenis</SelectItem>

                            {JENIS.map((item) => (
                                <SelectItem key={item} value={item}>
                                    {labelJenisPdp(item)}
                                </SelectItem>
                            ))}
                        </FieldSelect>
                    </Field>

                    <Field label="Keputusan">
                        <FieldSelect value={disetujui} onValueChange={setDisetujui}>
                            <SelectItem value="semua">Semua keputusan</SelectItem>
                            <SelectItem value="ya">Disetujui</SelectItem>
                            <SelectItem value="tidak">Tidak disetujui</SelectItem>
                        </FieldSelect>
                    </Field>

                    <Field label="Pengguna (id)">
                        <FieldInput
                            type="number"
                            min={1}
                            inputMode="numeric"
                            value={userId}
                            onChange={(event) => {
                                setUserId(event.target.value);
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

            {pdp.isPending ? (
                <SkeletonRows rows={5} />
            ) : pdp.isError ? (
                <AdminErrorState
                    title="Gagal memuat ledger persetujuan."
                    error={pdp.error}
                    onRetry={() => {
                        void pdp.refetch();
                    }}
                />
            ) : rows.length === 0 ? (
                <EmptyState
                    title="Belum ada catatan persetujuan pada filter ini."
                    description="Ubah atau hapus filter untuk melihat catatan lain."
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
                        {rows.map((baris) => (
                            <li
                                key={baris.id}
                                data-slot="admin-pdp-row"
                                className="border-border flex flex-col gap-2 rounded-lg border p-4 md:flex-row md:items-center md:justify-between"
                            >
                                <div className="flex flex-col gap-1">
                                    <p className="text-base font-medium">
                                        {labelJenisPdp(baris.jenis)}
                                    </p>

                                    <p className="text-muted-foreground text-sm tabular-nums">
                                        Pengguna #{baris.user_id} · dokumen versi{' '}
                                        {baris.versi_dokumen} ·{' '}
                                        {formatWaktuZona(baris.disetujui_at)}
                                    </p>
                                </div>

                                <PdpStatusBadge efektif={baris.disetujui} />
                            </li>
                        ))}
                    </ul>

                    <Pagination
                        meta={pdp.data?.meta}
                        onPageChange={(halaman) => {
                            terapkan(halaman);
                        }}
                    />
                </>
            )}
        </>
    );
}
