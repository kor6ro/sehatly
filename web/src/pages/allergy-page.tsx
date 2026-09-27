import { useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import {
    alergiOptions,
    destroyAlergiMutation,
    labelKeparahan,
    labelTipeAlergen,
} from '@/lib/api/alergi';
import { meOptions } from '@/lib/api/me';
import { isEmptyPage, isPastLastPage } from '@/lib/api/pagination';
import { ApiError } from '@/lib/http';
import type { Alergi } from '@/lib/api/types';
import { dispatchFlash } from '@/lib/flash';
import { formatWaktu } from '@/lib/format';
import { PageHeader } from '@/components/layout/page-header';
import { Pagination } from '@/components/layout/pagination';
import { SkeletonRows } from '@/components/states/loading-state';
import { EmptyState } from '@/components/states/empty-state';
import { ErrorState, ForbiddenState } from '@/components/states/error-state';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Spinner } from '@/components/ui/spinner';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { AlergiDialog } from '@/components/allergy/alergi-dialog';

const PER_PAGE = 10;

/**
 * `/profil/alergi` - full CRUD over `GET|POST /api/v1/pasien/alergi`.
 *
 * ## Why a 403 here is a screen and not a retry
 *
 * `PasienController` resolves the caller's own `pasien` row first, and an account with
 * none is refused with 403 on all ten patient routes. That is a statement about the
 * caller's account, so it gets its own copy and no "Coba lagi" button - asking again would
 * fail identically.
 *
 * ## The empty state is the common case, not an edge case
 *
 * A patient who has never recorded an allergy has `meta.total === 0`, and the screen says
 * so and offers the button that fixes it. A filter cannot produce a second kind of empty
 * here (this list has no filters), so `isPastLastPage` is the only other empty reading and
 * it gets its own copy too.
 */
export function AllergyPage() {
    const [page, setPage] = useState(1);
    const [creating, setCreating] = useState(false);
    const [editing, setEditing] = useState<Alergi | null>(null);
    const [deleting, setDeleting] = useState<Alergi | null>(null);

    const list = useQuery(alergiOptions({ page, per_page: PER_PAGE }));
    const me = useQuery(meOptions());
    const destroy = useMutation(destroyAlergiMutation());

    if (list.isPending) {
        return (
            <>
                <PageHeader
                    title="Alergi"
                    description="Memuat dari GET /api/v1/pasien/alergi."
                />

                <SkeletonRows rows={4} />
            </>
        );
    }

    if (list.isError) {
        if (list.error instanceof ApiError && list.error.isForbidden) {
            return (
                <>
                    <PageHeader title="Alergi" />

                    <ForbiddenState />
                </>
            );
        }

        return (
            <>
                <PageHeader title="Alergi" />

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
    const rows = data.alergi;
    const selfUserId = me.data?.data.user.id ?? null;

    return (
        <>
            <PageHeader
                title="Alergi"
                description="Daftar alergi yang dicatat pada akun ini. Ini adalah satu-satunya sumber data alergi di API; kolom catatan alergi pada profil tidak dikirim ke peramban."
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
                    title="Belum ada data alergi"
                    description="Catat alergi yang Anda miliki agar dapat digunakan sebagai peringatan oleh dokter saat konsultasi. Data ini adalah satu-satunya sumber data alergi di API."
                    action={
                        <Button
                            type="button"
                            onClick={() => {
                                setCreating(true);
                            }}
                        >
                            <Plus />

                            Tambah alergi
                        </Button>
                    }
                />
            ) : (
                <>
                    {isPastLastPage(meta, rows.length) ? (
                        <EmptyState
                            compact
                            title="Halaman ini kosong"
                            description={`Data alergi hanya tersedia sampai halaman ${String(meta?.last_page ?? 1)}.`}
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
                                    <AlergiRow
                                        row={row}
                                        selfUserId={selfUserId}
                                        deleting={destroy.isPending}
                                        onEdit={() => {
                                            setEditing(row);
                                        }}
                                        onDelete={() => {
                                            setDeleting(row);
                                        }}
                                    />
                                </li>
                            ))}
                        </ul>
                    )}

                    <Pagination meta={meta} onPageChange={setPage} />
                </>
            )}

            <AlergiDialog
                open={creating}
                row={null}
                selfUserId={selfUserId}
                onOpenChange={setCreating}
            />

            <AlergiDialog
                open={editing !== null}
                row={editing}
                selfUserId={selfUserId}
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
                        <DialogTitle>Hapus alergi</DialogTitle>

                        <DialogDescription>
                            Data {deleting?.nama_alergen ?? 'alergi ini'} akan dihapus
                            permanen. Tabel `pasien_alergi` tidak memiliki kolom soft delete,
                            jadi baris ini tidak dapat dipulihkan.
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
                                        setDeleting(null);

                                        dispatchFlash({
                                            level: 'error',
                                            message: describeFailure(error),
                                        });
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

function AlergiRow({
    row,
    selfUserId,
    onEdit,
    onDelete,
    deleting,
}: {
    row: Alergi;
    selfUserId: number | null;
    onEdit: () => void;
    onDelete: () => void;
    deleting: boolean;
}) {
    return (
        <Card>
            <CardContent className="flex flex-col gap-3">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="flex flex-col gap-1">
                        <p className="font-medium">{row.nama_alergen}</p>

                        <p className="text-muted-foreground text-sm">
                            {row.reaksi === null || row.reaksi === ''
                                ? 'Reaksi tidak dicatat'
                                : row.reaksi}
                        </p>
                    </div>

                    <div className="flex items-center gap-2">
                        <Button type="button" variant="outline" size="sm" onClick={onEdit}>
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

                <div className="flex flex-wrap items-center gap-2">
                    <Badge variant="secondary">{labelTipeAlergen(row.tipe_alergen)}</Badge>

                    <Badge
                        variant={
                            row.keparahan === 'berat' || row.keparahan === 'anafilaksis'
                                ? 'destructive'
                                : 'outline'
                        }
                    >
                        {labelKeparahan(row.keparahan)}
                    </Badge>

                    {selfUserId !== null &&
                    row.dicatat_oleh_user_id === selfUserId ? (
                        <Badge variant="outline">Dicatat sendiri</Badge>
                    ) : null}
                </div>

                <p className="text-muted-foreground text-xs">
                    Dicatat {formatWaktu(row.dibuat_at)}
                </p>
            </CardContent>
        </Card>
    );
}

/**
 * 403 and 404 are separate sentences because they are separate facts: one is about the
 * caller's account, the other about the row. A 404 after a delete means the row is already
 * gone, so it is reported that way rather than as something to retry.
 */
function describeFailure(error: unknown): string {
    if (error instanceof ApiError) {
        if (error.isForbidden) {
            return 'Akun ini tidak memiliki data pasien, sehingga operasi ditolak.';
        }

        if (error.isNotFound) {
            return 'Data alergi ini tidak ditemukan. Baris mungkin sudah dihapus.';
        }

        return error.message;
    }

    return error instanceof Error ? error.message : 'Terjadi kesalahan.';
}
