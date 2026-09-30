import { useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import {
    anggotaKeluargaOptions,
    destroyAnggotaKeluargaMutation,
    HUBUNGAN_KELUARGA,
    namaHubungan,
} from '@/lib/api/anggota-keluarga';
import { isEmptyPage, isPastLastPage } from '@/lib/api/pagination';
import { ApiError } from '@/lib/http';
import type { AnggotaKeluarga } from '@/lib/api/types';
import { dispatchFlash } from '@/lib/flash';
import {
    formatJenisKelamin,
    formatNikMasked,
    formatTanggal,
    formatTelepon,
} from '@/lib/format';
import { PageHeader } from '@/components/layout/page-header';
import { Pagination } from '@/components/layout/pagination';
import { SkeletonRows } from '@/components/states/loading-state';
import { EmptyState } from '@/components/states/empty-state';
import { ErrorState, ForbiddenState } from '@/components/states/error-state';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Separator } from '@/components/ui/separator';
import { Spinner } from '@/components/ui/spinner';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { AnggotaKeluargaDialog } from '@/components/family/anggota-keluarga-dialog';

const PER_PAGE = 10;

/**
 * `/profil/keluarga` - full CRUD over `GET|POST /api/v1/pasien/anggota-keluarga`.
 *
 * ## The three states, and why `meta.total` decides "empty"
 *
 * `isEmptyPage` and `isPastLastPage` are separate calls on purpose. An account with no
 * family members and a caller sitting on page 7 of a 3-page result both render an empty
 * array, and they need opposite copy: one is a state to explain, the other is a navigation
 * mistake to undo. Deciding on `rows.length` alone gives both the same blank card, and the
 * second leaves the user at a dead end.
 *
 * ## 403 and 404 are different screens, not the same red box
 *
 * A 403 means the account owns no `pasien` row at all - about the caller, actionable by
 * signing in as a patient, and not fixable by retrying. A 404 from a delete or an update
 * means the row is not the caller's or no longer exists, and `PasienController` uses it
 * precisely so existence is not leaked across tenants. `describeMutationFailure` keeps the
 * two apart, because telling a patient their own sibling does not exist would be worse
 * than the error.
 */
export function FamilyPage() {
    const [page, setPage] = useState(1);
    const [editing, setEditing] = useState<AnggotaKeluarga | null>(null);
    const [creating, setCreating] = useState(false);
    const [deleting, setDeleting] = useState<AnggotaKeluarga | null>(null);

    const list = useQuery(anggotaKeluargaOptions({ page, per_page: PER_PAGE }));
    const destroy = useMutation(destroyAnggotaKeluargaMutation());

    if (list.isPending) {
        return (
            <>
                <PageHeader
                    title="Anggota keluarga"
                    description="Memuat anggota keluarga."
                />

                <SkeletonRows rows={5} />
            </>
        );
    }

    if (list.isError) {
        if (list.error instanceof ApiError && list.error.isForbidden) {
            return (
                <>
                    <PageHeader title="Anggota keluarga" />

                    <ForbiddenState />
                </>
            );
        }

        return (
            <>
                <PageHeader title="Anggota keluarga" />

                <ErrorState
                    error={list.error}
                    onRetry={() => {
                        void list.refetch();
                    }}
                />
            </>
        );
    }

    const { data, meta } = list.data;
    const rows = data.anggota_keluarga;

    return (
        <>
            <PageHeader
                title="Anggota keluarga"
                description="Keluarga yang didaftarkan pada akun ini."
                action={
                    <Button
                        type="button"
                        onClick={() => {
                            setCreating(true);
                        }}
                    >
                        <Plus />

                        Tambah
                    </Button>
                }
            />

            {isEmptyPage(meta, rows.length) ? (
                <EmptyState
                    title="Belum ada anggota keluarga"
                    description="Daftarkan anggota keluarga agar dapat dipilih saat membuat janji konsultasi. Anggota keluarga tidak memiliki akun sendiri."
                    action={
                        <Button
                            type="button"
                            onClick={() => {
                                setCreating(true);
                            }}
                        >
                            <Plus />

                            Tambah anggota keluarga
                        </Button>
                    }
                />
            ) : (
                <>
                    {isPastLastPage(meta, rows.length) ? (
                        <EmptyState
                            compact
                            title="Halaman ini kosong"
                            description={`Data anggota keluarga hanya tersedia sampai halaman ${String(meta?.last_page ?? 1)}.`}
                            action={
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => {
                                        setPage(1);
                                    }}
                                >
                                    Kembali ke halaman pertama
                                </Button>
                            }
                        />
                    ) : (
                        <ul className="flex flex-col gap-3">
                            {rows.map((row) => (
                                <li key={row.id}>
                                    <FamilyRow
                                        row={row}
                                        onEdit={() => {
                                            setEditing(row);
                                        }}
                                        onDelete={() => {
                                            setDeleting(row);
                                        }}
                                        deleting={destroy.isPending}
                                    />
                                </li>
                            ))}
                        </ul>
                    )}

                    <Pagination meta={meta} onPageChange={setPage} />
                </>
            )}

            <AnggotaKeluargaDialog
                open={creating}
                row={null}
                onOpenChange={(open) => {
                    setCreating(open);
                }}
            />

            <AnggotaKeluargaDialog
                open={editing !== null}
                row={editing}
                onOpenChange={(open) => {
                    if (!open) {
                        setEditing(null);
                    }
                }}
            />

            <Dialog
                open={deleting !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setDeleting(null);
                    }
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Hapus anggota keluarga</DialogTitle>

                        <DialogDescription>
                            Data {deleting?.nama_lengkap ?? 'anggota keluarga ini'} akan
                            dihapus permanen dan tidak dapat dipulihkan kembali.
                        </DialogDescription>
                    </DialogHeader>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => {
                                setDeleting(null);
                            }}
                        >
                            Batal
                        </Button>

                        <Button
                            type="button"
                            variant="destructive"
                            disabled={destroy.isPending}
                            onClick={() => {
                                if (deleting === null) {
                                    return;
                                }

                                destroy.mutate(deleting.id, {
                                    onSuccess: (result) => {
                                        setDeleting(null);

                                        dispatchFlash({
                                            level: 'success',
                                            message: result.message,
                                        });
                                    },
                                    onError: (error) => {
                                        dispatchFlash({
                                            level: 'error',
                                            message: describeMutationFailure(error),
                                        });

                                        setDeleting(null);
                                    },
                                });
                            }}
                        >
                            {destroy.isPending ? <Spinner /> : <Trash2 />}

                            Hapus
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

function FamilyRow({
    row,
    onEdit,
    onDelete,
    deleting,
}: {
    row: AnggotaKeluarga;
    onEdit: () => void;
    onDelete: () => void;
    deleting: boolean;
}) {
    return (
        <Card>
            <CardContent className="flex flex-col gap-3">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="flex flex-col gap-1">
                        <p className="font-medium">{row.nama_lengkap}</p>

                        <p className="text-muted-foreground text-sm">
                            {namaHubungan(row.hubungan_id, row.hubungan)} -{' '}
                            {formatJenisKelamin(row.jenis_kelamin)} -{' '}
                            {formatTanggal(row.tanggal_lahir)}
                        </p>
                    </div>

                    <div className="flex items-center gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={onEdit}
                        >
                            <Pencil />

                            Ubah
                        </Button>

                        <Button
                            type="button"
                            variant="destructive"
                            size="sm"
                            disabled={deleting}
                            onClick={onDelete}
                        >
                            <Trash2 />

                            Hapus
                        </Button>
                    </div>
                </div>

                <Separator />

                <dl className="grid gap-3 sm:grid-cols-3">
                    <Cell label="NIK" value={formatNikMasked(row.nik)} mono />

                    <Cell label="Telepon" value={formatTelepon(row.no_telepon)} />

                    <Cell label="Hubungan (id)" value={String(row.hubungan_id)} />
                </dl>

                {row.catatan_alergi === null || row.catatan_alergi === '' ? null : (
                    <Badge variant="secondary" className="w-fit">
                        Catatan alergi: {row.catatan_alergi}
                    </Badge>
                )}

                <p className="text-muted-foreground text-xs">
                    Hubungan tersedia: {HUBUNGAN_KELUARGA.length} pilihan hubungan keluarga.
                </p>
            </CardContent>
        </Card>
    );
}

function Cell({
    label,
    value,
    mono = false,
}: {
    label: string;
    value: string;
    mono?: boolean;
}) {
    return (
        <div className="flex flex-col gap-0.5">
            <dt className="text-muted-foreground text-xs">{label}</dt>

            <dd className={mono ? 'font-mono text-sm' : 'text-sm font-medium'}>
                {value}
            </dd>
        </div>
    );
}

/**
 * The delete failure, worded by status.
 *
 * 403 and 404 are separated because they are different facts about different subjects: 403
 * is about the caller's account, 404 is about the row. A 404 after a delete usually means
 * the row was already gone, so it is reported as such rather than as a failure to retry.
 */
function describeMutationFailure(error: unknown): string {
    if (error instanceof ApiError) {
        if (error.isForbidden) {
            return 'Akun ini tidak memiliki data pasien, sehingga operasi ditolak.';
        }

        if (error.isNotFound) {
            return 'Anggota keluarga ini tidak ditemukan. Baris mungkin sudah dihapus.';
        }

        return error.message;
    }

    return error instanceof Error ? error.message : 'Terjadi kesalahan.';
}
