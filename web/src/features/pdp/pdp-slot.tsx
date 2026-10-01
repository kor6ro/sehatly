import { useRef, useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import {
    AlertCircle,
    CheckCircle2,
    ChevronDown,
    ExternalLink,
    Loader2,
} from 'lucide-react';
import { Link } from 'react-router';
import { ApiError } from '@/lib/http';
import { formatTanggal } from '@/lib/format';
import { formatWaktuZona } from '@/lib/waktu';
import { queryClient } from '@/lib/query-client';
import {
    catatPersetujuanMutation,
    pdpDokumenQueryKey,
    pdpPersetujuanQueryKey,
} from '@/lib/api/pdp-persetujuan';
import type { PersetujuanPdp } from '@/lib/api/types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { PdpStatusBadge } from '@/features/pdp/pdp-status-badge';
import {
    JUDUL_JENIS,
    MASA_SIMPAN,
    RINCIAN_JENIS,
    TAUTAN_JENIS,
    TUJUAN_JENIS,
} from '@/features/pdp/pdp-teks';

/**
 * One consent slot: title, purpose, tri-state status, an explicit decision, and the
 * recorded detail behind a disclosure.
 *
 * ## The decision is one click, and the version comes from the server
 *
 * An unanswered slot renders a `ToggleGroup` with a controlled empty value, so nothing is
 * selected before the patient acts (owner decision + EDPB/ICO "no pre-ticked boxes"). A
 * click posts immediately - there is no field to fill (F02 metric M-1) - and the
 * `versi_dokumen` in that body is the ACTIVE version passed down from
 * `GET /pdp/dokumen`, never a client constant.
 *
 * ## Why a recorded answer goes through a dialog and a fresh one does not
 *
 * A first answer is additive and reversible in one more click, so a dialog would just
 * add a step. Changing a recorded answer is different: the person is about to overwrite a
 * decision that is on the record, and `_global.md` §4 wants the consequence stated before
 * that happens. Withdrawal on the SAME version is allowed at any time, which is why the
 * dialog's confirm is the only destructive-looking control on the card.
 *
 * ## Why the radio never turns itself on
 *
 * The group is controlled with `value=""` and never updated from a click. While the write
 * is in flight the card says "Menyimpan..." instead of showing a checked option, so a
 * refused write cannot leave a decision looking recorded. The badge and the colophon
 * change only after the server's `efektif` comes back through a refetch.
 */
export function PdpSlot({
    entri,
    versiAktif,
    berlakuSejak,
    online,
}: {
    entri: PersetujuanPdp;
    /** The active version from `GET /pdp/dokumen`, or `null` while it is unavailable. */
    versiAktif: string | null;
    /** `berlaku_sejak` of the active version, `Y-m-d`; shown in the disclosure. */
    berlakuSejak: string | null;
    online: boolean;
}) {
    const [dialog, setDialog] = useState<'tarik' | 'ubah' | null>(null);
    const [berhasil, setBerhasil] = useState<boolean | null>(null);
    const [galat, setGalat] = useState<unknown>(null);

    const pemicuRef = useRef<HTMLButtonElement>(null);

    const kirim = useMutation(catatPersetujuanMutation());

    const judul = JUDUL_JENIS[entri.jenis];
    const tautan = TAUTAN_JENIS[entri.jenis];
    const rincian = RINCIAN_JENIS[entri.jenis];

    const terkunci = !online || kirim.isPending || versiAktif === null;

    function catat(disetujui: boolean): void {
        if (versiAktif === null) {
            return;
        }

        setGalat(null);
        setBerhasil(null);

        kirim.mutate(
            {
                jenis: entri.jenis,
                versi_dokumen: versiAktif,
                disetujui,
            },
            {
                onSuccess: () => {
                    setDialog(null);
                    setBerhasil(disetujui);
                },
                onError: (error) => {
                    setDialog(null);
                    setGalat(error);

                    if (
                        error instanceof ApiError &&
                        error.isValidation &&
                        error.fieldErrors('versi_dokumen').length > 0
                    ) {
                        void queryClient.invalidateQueries({
                            queryKey: pdpPersetujuanQueryKey,
                        });

                        void queryClient.invalidateQueries({
                            queryKey: pdpDokumenQueryKey,
                        });
                    }
                },
            },
        );
    }

    const pesanVersi =
        galat instanceof ApiError ? galat.fieldErrors('versi_dokumen') : [];

    return (
        <Card data-slot="pdp-slot" data-jenis={entri.jenis}>
            <CardHeader>
                <div className="flex items-start justify-between gap-2">
                    <CardTitle className="text-base">{judul}</CardTitle>

                    <PdpStatusBadge efektif={entri.efektif} className="shrink-0" />
                </div>

                {entri.jenis === 'berbagi_data_medis' ? (
                    <Badge variant="outline" className="text-muted-foreground w-fit">
                        Dibutuhkan untuk berbagi data
                    </Badge>
                ) : null}

                <p className="text-muted-foreground text-sm">{TUJUAN_JENIS[entri.jenis]}</p>
            </CardHeader>

            <CardContent className="flex flex-col gap-3">
                {entri.versi_dokumen === null ? null : (
                    <p className="text-muted-foreground text-sm">
                        Versi dokumen {entri.versi_dokumen} • Dicatat{' '}
                        <span className="whitespace-nowrap">
                            {formatWaktuZona(entri.disetujui_at)}
                        </span>
                    </p>
                )}

                {entri.efektif === null ? (
                    <ToggleGroup
                        type="single"
                        variant="outline"
                        value=""
                        disabled={terkunci}
                        aria-label={`Keputusan untuk ${judul}`}
                        onValueChange={(nilai) => {
                            if (nilai === 'ya') {
                                catat(true);
                            }

                            if (nilai === 'tidak') {
                                catat(false);
                            }
                        }}
                        className="grid w-full grid-cols-2 gap-2 data-[variant=outline]:shadow-none"
                    >
                        <ToggleGroupItem
                            value="ya"
                            className="h-11 w-full"
                            aria-label={`Setujui ${judul}`}
                        >
                            Setujui
                        </ToggleGroupItem>

                        <ToggleGroupItem
                            value="tidak"
                            className="h-11 w-full"
                            aria-label={`Tidak setujui ${judul}`}
                        >
                            Tidak setujui
                        </ToggleGroupItem>
                    </ToggleGroup>
                ) : (
                    <div className="flex flex-wrap items-center gap-2">
                        {entri.efektif ? (
                            <Button
                                ref={pemicuRef}
                                data-slot="pdp-keputusan"
                                type="button"
                                variant="outline"
                                className="text-destructive hover:text-destructive min-h-11"
                                disabled={terkunci}
                                onClick={() => {
                                    setDialog('tarik');
                                }}
                            >
                                Tarik persetujuan
                            </Button>
                        ) : (
                            <Button
                                ref={pemicuRef}
                                data-slot="pdp-keputusan"
                                type="button"
                                className="min-h-11"
                                disabled={terkunci}
                                onClick={() => {
                                    setDialog('ubah');
                                }}
                            >
                                Setujui
                            </Button>
                        )}
                    </div>
                )}

                {kirim.isPending ? (
                    <p
                        role="status"
                        className="text-muted-foreground flex items-center gap-2 text-sm"
                    >
                        <Loader2 aria-hidden className="size-4 animate-spin" />

                        Menyimpan keputusan...
                    </p>
                ) : null}

                {berhasil === null ? null : (
                    <Alert
                        role="status"
                        data-slot="pdp-simpan-status"
                        className="border-success/40 bg-success/10"
                    >
                        <CheckCircle2 aria-hidden className="text-success" />

                        <AlertTitle>Persetujuan dicatat.</AlertTitle>

                        <AlertDescription className="text-foreground">
                            {judul}: {berhasil ? 'Disetujui' : 'Tidak disetujui'}
                            {versiAktif === null ? '.' : `, versi ${versiAktif}.`}
                        </AlertDescription>
                    </Alert>
                )}

                {galat === null ? null : pesanVersi.length > 0 ? (
                    <Alert variant="destructive" role="alert" data-slot="pdp-versi-bentrok">
                        <AlertCircle aria-hidden />

                        <AlertTitle>Versi dokumen berubah</AlertTitle>

                        <AlertDescription>
                            <p>{pesanVersi[0]}</p>
                            <p>Menampilkan data terbaru dari server.</p>
                        </AlertDescription>
                    </Alert>
                ) : (
                    <Alert variant="destructive" role="alert" data-slot="pdp-simpan-galat">
                        <AlertCircle aria-hidden />

                        <AlertTitle>Gagal menyimpan keputusan.</AlertTitle>

                        <AlertDescription>
                            <p>Coba lagi.</p>
                        </AlertDescription>
                    </Alert>
                )}

                <Collapsible>
                    <CollapsibleTrigger asChild>
                        <Button
                            type="button"
                            variant="ghost"
                            className="group min-h-11 w-full justify-between px-0 hover:bg-transparent"
                        >
                            Baca selengkapnya

                            <ChevronDown
                                aria-hidden
                                className="size-4 transition-transform duration-200 group-data-[state=open]:rotate-180"
                            />
                        </Button>
                    </CollapsibleTrigger>

                    <CollapsibleContent className="flex flex-col gap-3 pt-2">
                        <dl className="flex flex-col gap-3 text-sm">
                            <div className="flex flex-col gap-1">
                                <dt className="font-medium">Data dan tujuan</dt>
                                <dd className="text-muted-foreground">{rincian.data}</dd>
                            </div>

                            <div className="flex flex-col gap-1">
                                <dt className="font-medium">Masa simpan</dt>
                                <dd className="text-muted-foreground">{MASA_SIMPAN}</dd>
                            </div>

                            <div className="flex flex-col gap-1">
                                <dt className="font-medium">Cara mengubah</dt>
                                <dd className="text-muted-foreground">{rincian.ubah}</dd>
                            </div>

                            {versiAktif === null ? null : (
                                <div className="flex flex-col gap-1">
                                    <dt className="font-medium">Versi berlaku saat ini</dt>
                                    <dd className="text-muted-foreground">
                                        Versi {versiAktif}
                                        {berlakuSejak === null
                                            ? '.'
                                            : `, berlaku sejak ${formatTanggal(berlakuSejak)}.`}
                                    </dd>
                                </div>
                            )}

                            {entri.versi_dokumen === null ? null : (
                                <div className="flex flex-col gap-1">
                                    <dt className="font-medium">Alamat IP</dt>
                                    <dd
                                        data-slot="pdp-ip-detail"
                                        className="text-muted-foreground"
                                    >
                                        {entri.ip_address ?? 'Tidak tercatat'}
                                    </dd>
                                </div>
                            )}
                        </dl>
                    </CollapsibleContent>
                </Collapsible>

                <p className="text-sm">
                    <Link
                        to={tautan.to}
                        className="text-primary inline-flex items-center gap-1 underline-offset-4 hover:underline"
                    >
                        {tautan.label}

                        <ExternalLink aria-hidden className="size-3.5" />
                    </Link>
                </p>
            </CardContent>

            <Dialog
                open={dialog !== null}
                onOpenChange={(terbuka) => {
                    if (!terbuka) {
                        setDialog(null);
                    }
                }}
            >
                <DialogContent
                    onCloseAutoFocus={(event) => {
                        if (pemicuRef.current !== null) {
                            event.preventDefault();

                            pemicuRef.current.focus();
                        }
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>
                            {dialog === 'tarik' ? 'Tarik persetujuan?' : 'Ubah jawaban?'}
                        </DialogTitle>

                        <DialogDescription>
                            {dialog === 'tarik'
                                ? 'Data Anda tidak lagi dipakai berdasarkan persetujuan ini. Anda bisa memberi izin lagi kapan saja.'
                                : 'Data Anda akan dipakai berdasarkan persetujuan baru.'}
                        </DialogDescription>
                    </DialogHeader>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant={dialog === 'tarik' ? 'destructive' : 'default'}
                            className="min-h-11"
                            disabled={kirim.isPending}
                            onClick={() => {
                                catat(dialog === 'tarik' ? false : true);
                            }}
                        >
                            {kirim.isPending ? <Loader2 aria-hidden className="animate-spin" /> : null}

                            {dialog === 'tarik' ? 'Ya, tarik persetujuan' : 'Ya, ubah jawaban'}
                        </Button>

                        <Button
                            type="button"
                            variant="outline"
                            className="min-h-11"
                            onClick={() => {
                                setDialog(null);
                            }}
                        >
                            Batal
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </Card>
    );
}
