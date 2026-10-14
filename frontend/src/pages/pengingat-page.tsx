import { useState } from 'react';
import { Link } from 'react-router';
import { useMutation, useQuery } from '@tanstack/react-query';
import { AlertCircle, Check, Plus } from 'lucide-react';
import { ApiError } from '@/lib/http';
import { useDocumentTitle } from '@/hooks/use-document-title';
import { useOnlineStatus } from '@/hooks/use-online-status';
import {
    deletePengingatMutation,
    pengingatOptions,
    updatePengingatStatusMutation,
    type Pengingat,
} from '@/lib/api/pengingat';
import { PageHeader } from '@/components/layout/page-header';
import { OfflineBanner } from '@/components/offline-banner';
import { Button } from '@/components/ui/button';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { SkeletonRows } from '@/components/states/loading-state';
import { EmptyState } from '@/components/states/empty-state';
import { ErrorState, ForbiddenState } from '@/components/states/error-state';
import { PengingatFormDialog } from '@/features/pengingat/pengingat-form-dialog';
import { PengingatList } from '@/features/pengingat/pengingat-list';

/**
 * `/pengingat` - "Pengingat saya".
 *
 * ## Only the four routes the API has
 *
 * List, create, update (including the `status` pause/resume) and delete. There is no
 * "sudah diminum" endpoint and no snooze endpoint, so the row offers exactly what the
 * contract supports. "Tandai selesai" is not offered separately: `selesai` is terminal
 * and nothing writes it automatically, so a user who wants to close a reminder pauses it
 * through the same status transition.
 *
 * ## Reads are paged; the page size is the API cap
 *
 * `IndexPengingatRequest::PER_PAGE_MAKS` is 100, but the list is grouped by day and a
 * page of 50 keeps the read small while `Pagination` handles the rare account with more.
 *
 * ## Mutations report inline, never as a toast
 *
 * Creating, pausing and deleting all change the screen, so the server's own message is
 * rendered in a persistent `role="status"` line and the list is refetched. Errors keep
 * the user's place with a `role="alert"` alert.
 */
export function PengingatPage() {
    useDocumentTitle('Pengingat saya | Sehatly');

    const online = useOnlineStatus();
    const [page, setPage] = useState(1);
    const [dialog, setDialog] = useState<{ terbuka: boolean; row: Pengingat | null }>({
        terbuka: false,
        row: null,
    });
    const [targetHapus, setTargetHapus] = useState<Pengingat | null>(null);
    const [pesan, setPesan] = useState<string | null>(null);
    const [galatAksi, setGalatAksi] = useState<unknown>(null);

    const daftar = useQuery(pengingatOptions({ page, per_page: 50 }));
    const toggleStatus = useMutation(updatePengingatStatusMutation());
    const hapus = useMutation(deletePengingatMutation());

    const pendingId = toggleStatus.isPending
        ? (toggleStatus.variables?.id ?? null)
        : hapus.isPending
          ? (hapus.variables ?? null)
          : null;

    function bukaDialog(row: Pengingat | null): void {
        setPesan(null);
        setGalatAksi(null);
        setDialog({ terbuka: true, row });
    }

    function suksesForm(teks: string): void {
        setDialog({ terbuka: false, row: null });
        setPesan(teks);
        setGalatAksi(null);
    }

    function toggle(row: Pengingat): void {
        setPesan(null);
        setGalatAksi(null);

        toggleStatus.mutate(
            {
                id: row.id,
                status: row.status === 'aktif' ? 'nonaktif' : 'aktif',
            },
            {
                onSuccess: (hasil) => {
                    setPesan(hasil.message);
                },
                onError: (error) => {
                    setGalatAksi(error);
                },
            },
        );
    }

    function konfirmasiHapus(): void {
        if (targetHapus === null) {
            return;
        }

        setPesan(null);
        setGalatAksi(null);

        hapus.mutate(targetHapus.id, {
            onSuccess: (hasil) => {
                setPesan(hasil.message);
                setTargetHapus(null);
            },
            onError: (error) => {
                setGalatAksi(error);
                setTargetHapus(null);
            },
        });
    }

    if (daftar.isPending) {
        return (
            <>
                <PageHeader
                    title="Pengingat saya"
                    description="Pengingat obat dan janji temu sesuai zona waktu Anda."
                />

                <div data-slot="pengingat-loading">
                    <SkeletonRows rows={4} />
                </div>
            </>
        );
    }

    if (
        daftar.isError &&
        daftar.error instanceof ApiError &&
        daftar.error.isForbidden
    ) {
        return (
            <>
                <PageHeader title="Pengingat saya" />

                <ForbiddenState
                    detail={daftar.error.message}
                    action={
                        <Button asChild variant="outline" className="min-h-11">
                            <Link to="/dashboard">Kembali</Link>
                        </Button>
                    }
                />
            </>
        );
    }

    if (daftar.isError && daftar.data === undefined) {
        return (
            <>
                <PageHeader title="Pengingat saya" />

                <ErrorState
                    error={daftar.error}
                    title="Gagal memuat pengingat."
                    onRetry={() => {
                        void daftar.refetch();
                    }}
                />
            </>
        );
    }

    const rows = daftar.data?.data.pengingat ?? [];
    const meta = daftar.data?.meta;

    return (
        <>
            <PageHeader
                title="Pengingat saya"
                description="Pengingat obat dan janji temu sesuai zona waktu Anda."
                action={
                    <Button
                        type="button"
                        data-slot="pengingat-buat"
                        className="min-h-11"
                        disabled={!online}
                        aria-disabled={!online ? true : undefined}
                        onClick={() => {
                            bukaDialog(null);
                        }}
                    >
                        <Plus aria-hidden />

                        Buat pengingat
                    </Button>
                }
            />

            <OfflineBanner message="Anda sedang offline. Pengingat yang tampil adalah data terakhir yang tersimpan." />

            {!online ? (
                <p
                    data-slot="pengingat-offline-alasan"
                    className="text-muted-foreground text-sm"
                >
                    Membuat, mengubah, dan menghapus pengingat dinonaktifkan sampai koneksi
                    kembali.
                </p>
            ) : null}

            {pesan === null ? null : (
                <p
                    role="status"
                    data-slot="pengingat-sukses"
                    className="text-foreground flex items-center gap-2 text-sm"
                >
                    <Check aria-hidden className="text-success size-4 shrink-0" />

                    {pesan}
                </p>
            )}

            {galatAksi === null ? null : (
                <Alert
                    variant="destructive"
                    role="alert"
                    data-slot="pengingat-galat-aksi"
                >
                    <AlertCircle />

                    <AlertTitle>Gagal memperbarui pengingat.</AlertTitle>

                    <AlertDescription>
                        <p>
                            {galatAksi instanceof ApiError
                                ? galatAksi.message
                                : 'Terjadi kesalahan yang tidak diketahui.'}
                        </p>
                    </AlertDescription>
                </Alert>
            )}

            {rows.length === 0 ? (
                <EmptyState
                    title="Belum ada pengingat."
                    description="Buat pengingat obat atau janji temu agar Anda tidak melewatkan jadwal."
                    action={
                        <Button
                            type="button"
                            variant="outline"
                            className="min-h-11"
                            data-slot="pengingat-buat-kosong"
                            disabled={!online}
                            aria-disabled={!online ? true : undefined}
                            onClick={() => {
                                bukaDialog(null);
                            }}
                        >
                            <Plus aria-hidden />

                            Buat pengingat
                        </Button>
                    }
                />
            ) : (
                <PengingatList
                    rows={rows}
                    meta={meta}
                    online={online}
                    pendingId={pendingId}
                    onUbah={(row) => {
                        bukaDialog(row);
                    }}
                    onToggleStatus={toggle}
                    onHapus={(row) => {
                        setPesan(null);
                        setGalatAksi(null);
                        setTargetHapus(row);
                    }}
                    onPageChange={setPage}
                />
            )}

            <PengingatFormDialog
                open={dialog.terbuka}
                onOpenChange={(open) => {
                    setDialog((sebelumnya) => ({
                        terbuka: open,
                        row: open ? sebelumnya.row : null,
                    }));
                }}
                row={dialog.row}
                onSukses={suksesForm}
            />

            <Dialog
                open={targetHapus !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setTargetHapus(null);
                    }
                }}
            >
                <DialogContent data-slot="pengingat-hapus-dialog">
                    <DialogHeader>
                        <DialogTitle>Hapus pengingat?</DialogTitle>

                        <DialogDescription>
                            Pengingat ini akan dihapus permanen dan tidak dapat
                            dikembalikan. Notifikasi yang sudah terkirim tetap tersimpan di
                            kotak masuk.
                        </DialogDescription>
                    </DialogHeader>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            className="min-h-11"
                            onClick={() => {
                                setTargetHapus(null);
                            }}
                        >
                            Batal
                        </Button>

                        <Button
                            type="button"
                            variant="destructive"
                            data-slot="pengingat-hapus-konfirmasi"
                            className="min-h-11"
                            disabled={hapus.isPending}
                            onClick={konfirmasiHapus}
                        >
                            {hapus.isPending ? 'Menghapus...' : 'Ya, hapus'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
