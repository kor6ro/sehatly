import { History } from 'lucide-react';
import { formatTanggal, formatWaktu, formatNikMasked } from '@/lib/format';
import {
    LABEL_JENIS_DIAGNOSA,
    LABEL_STATUS_TINDAK_LANJUT,
    LABEL_TIPE_KUNJUNGAN,
    LABEL_TIPE_LAMPIRAN,
} from '@/lib/api/rekam-medis';
import type { RekamMedis } from '@/lib/api/types';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import { Badge } from '@/components/ui/badge';
import { StatusDokumenBadge, VersiBadge } from '@/features/konsultasi/status-badge';

/**
 * The read-only medical record, plus the amendment chain.
 *
 * ## Read-only for a patient, and the chain is the reason that is not paternalism
 *
 * A patient sees the head of the chain and the whole list of revisions. They do not
 * see an edit form, because every write on this table is `tipe:dokter` and
 * `permission:rekam_medis.simpan` - so the absence is a rendering of the server's own
 * rule rather than a client-side guess, and the server would 403 the attempt anyway.
 *
 * ## The chain has no linkage column, and this renders the consequence
 *
 * `rekam_medis` has no self-referencing foreign key and no `parent_id`, so the chain
 * is reconstructed by `(pasien_id, dokter_id, tanggal_periksa)` ordered by `versi` -
 * a **convention**, not a constraint. The `ran` block publishes that reconstruction
 * in full, ascending and untruncated, and `adalah_versi_terkini` names the head.
 *
 * Each entry is a reduced projection: id, `versi`, `status_dokumen`, `keluhan_utama`,
 * `diagnosis_kerja` and the two timestamps. Opening one is a SEPARATE read and a
 * separate `akses_rekam_medis_log` row, which is the correct accounting - it is why
 * the entries are shown rather than expanded here.
 *
 * ## `jadwal_kontrol` is rendered with `formatTanggal`, never `formatWaktu`
 *
 * It is a `DATE` published as `Y-m-d`. `formatWaktu` would hand it to `new Date()`,
 * which parses a bare `Y-m-d` as UTC midnight and shifts the calendar day for any
 * reader in a negative offset - moving a next-day appointment to the previous
 * evening.
 */
export function RekamMedisView({
    rekam,
    className,
}: {
    rekam: RekamMedis;
    className?: string;
}) {
    const rantai = rekam.ran ?? [];

    return (
        <div className={className}>
            <Card data-slot="rekam-medis-view">
                <CardHeader>
                    <CardTitle className="flex flex-wrap items-center gap-2">
                        Rekam medis
                        <StatusDokumenBadge status={rekam.status_dokumen} />
                        <VersiBadge
                            versi={rekam.versi}
                            terbaru={rekam.adalah_versi_terkini}
                        />
                    </CardTitle>

                    <CardDescription>
                        Dibaca dari GET /api/v1/rekam-medis/{rekam.id}. Setiap
                        pembacaan tercatat pada akses_rekam_medis_log.
                    </CardDescription>
                </CardHeader>

                <CardContent className="flex flex-col gap-6">
                    <section
                        data-slot="rekam-medis-identitas"
                        className="flex flex-col gap-2"
                    >
                        <Baris label="Pasien" nilai={rekam.pasien?.nama_lengkap ?? '-'} />
                        <Baris label="NIK" nilai={formatNikMasked(rekam.pasien?.nik ?? null)} />
                        <Baris label="Dokter" nilai={rekam.dokter?.nama_lengkap ?? '-'} />
                        <Baris
                            label="Tipe kunjungan"
                            nilai={
                                LABEL_TIPE_KUNJUNGAN[rekam.tipe_kunjungan] ??
                                rekam.tipe_kunjungan
                            }
                        />
                        <Baris
                            label="Tanggal periksa"
                            nilai={formatWaktu(rekam.tanggal_periksa)}
                        />
                        {rekam.konsultasi_id === null ? (
                            <p className="text-warning text-xs">
                                Rekam medis ini tidak tertaut ke konsultasi mana pun,
                                sehingga rantai versinya tidak dapat direkonstruksi
                                oleh (pasien_id, dokter_id, tanggal_periksa).
                            </p>
                        ) : null}
                    </section>

                    <Separator />

                    <section data-slot="rekam-medis-soap" className="flex flex-col gap-4">
                        <Blok judul="Keluhan utama" isi={rekam.keluhan_utama} />
                        <Blok judul="Riwayat penyakit sekarang" isi={rekam.riwayat_penyakit_sekarang} />
                        <Blok judul="Riwayat penyakit dahulu" isi={rekam.riwayat_penyakit_dahulu} />
                        <Blok judul="Riwayat keluarga" isi={rekam.riwayat_keluarga} />
                        <Blok judul="Riwayat psikososial" isi={rekam.riwayat_psikososial} />
                        <Blok judul="Hasil pemeriksaan fisik" isi={rekam.hasil_pemeriksaan_fisik} />

                        <Blok judul="Subjektif (S)" isi={rekam.subjektif} />
                        <Blok judul="Objektif (O)" isi={rekam.objektif} />
                        <Blok judul="Asesmen (A)" isi={rekam.asesmen} />
                        <Blok judul="Plan (P)" isi={rekam.plan} />

                        <Blok judul="Diagnosis kerja" isi={rekam.diagnosis_kerja} />
                        <Blok judul="Instruksi tindak lanjut" isi={rekam.instruksi_tindak_lanjut} />

                        <Baris
                            label="Status tindak lanjut"
                            nilai={
                                rekam.status_tindak_lanjut === null
                                    ? '-'
                                    : (LABEL_STATUS_TINDAK_LANJUT[
                                          rekam.status_tindak_lanjut
                                      ] ?? rekam.status_tindak_lanjut)
                            }
                        />
                        <Baris
                            label="Jadwal kontrol"
                            nilai={formatTanggal(rekam.jadwal_kontrol)}
                        />
                    </section>

                    {(rekam.diagnosa?.length ?? 0) > 0 ? (
                        <>
                            <Separator />

                            <section
                                data-slot="rekam-medis-diagnosa"
                                className="flex flex-col gap-2"
                            >
                                <h3 className="text-sm font-semibold">Diagnosis</h3>

                                <ul className="flex flex-col gap-1">
                                    {(rekam.diagnosa ?? []).map((baris) => (
                                        <li key={baris.id} className="text-sm">
                                            <Badge variant="secondary" className="mr-2">
                                                {LABEL_JENIS_DIAGNOSA[baris.jenis] ??
                                                    baris.jenis}
                                            </Badge>
                                            <span className="font-mono">
                                                {baris.icd10_kode}
                                            </span>{' '}
                                            {baris.deskripsi ?? '-'}
                                        </li>
                                    ))}
                                </ul>
                            </section>
                        </>
                    ) : null}

                    {(rekam.tindakan?.length ?? 0) > 0 ? (
                        <>
                            <Separator />

                            <section
                                data-slot="rekam-medis-tindakan"
                                className="flex flex-col gap-2"
                            >
                                <h3 className="text-sm font-semibold">Tindakan</h3>

                                <ul className="flex flex-col gap-1">
                                    {(rekam.tindakan ?? []).map((baris) => (
                                        <li key={baris.id} className="text-sm">
                                            <span className="font-mono">
                                                {baris.icd9cm_kode ?? '-'}
                                            </span>{' '}
                                            {baris.nama_tindakan}
                                            <span className="text-muted-foreground">
                                                {' '}
                                                {formatWaktu(baris.tanggal_tindakan)}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            </section>
                        </>
                    ) : null}

                    {(rekam.lampiran?.length ?? 0) > 0 ? (
                        <>
                            <Separator />

                            <section
                                data-slot="rekam-medis-lampiran"
                                className="flex flex-col gap-2"
                            >
                                <h3 className="text-sm font-semibold">Lampiran</h3>

                                <ul className="flex flex-col gap-1">
                                    {(rekam.lampiran ?? []).map((baris) => (
                                        <li key={baris.id} className="text-sm">
                                            <a
                                                href={baris.file_url}
                                                target="_blank"
                                                rel="noreferrer"
                                                className="text-primary underline"
                                            >
                                                {baris.nama_file}
                                            </a>{' '}
                                            <span className="text-muted-foreground">
                                                {LABEL_TIPE_LAMPIRAN[baris.tipe] ??
                                                    baris.tipe}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            </section>
                        </>
                    ) : null}

                    <Separator />

                    <RantaiVersi rantai={rantai} />
                </CardContent>
            </Card>
        </div>
    );
}

/**
 * The amendment chain, ascending by `versi`, with the head marked.
 *
 * The list is never truncated, which is `RekamMedisResource`'s own rule and this
 * component's: a medical record that silently hides a revision is the one thing it
 * may not do. When a chain has exactly one entry this says so, because "no
 * amendments" and "amendments not loaded" are different facts and only the count
 * distinguishes them.
 */
function RantaiVersi({
    rantai,
}: {
    rantai: NonNullable<RekamMedis['ran']>;
}) {
    if (rantai.length === 0) {
        return (
            <p className="text-muted-foreground text-sm">
                Rantai versi tidak dimuat pada respons ini.
            </p>
        );
    }

    return (
        <section data-slot="rekam-medis-rantai" className="flex flex-col gap-2">
            <h3 className="flex items-center gap-2 text-sm font-semibold">
                <History aria-hidden />

                Rantai versi ({rantai.length})
            </h3>

            <ol className="flex flex-col gap-2">
                {rantai.map((entri) => (
                    <li
                        key={entri.id}
                        data-slot="rekam-medis-rantai-entri"
                        data-versi={entri.versi}
                        className="bg-muted/40 flex flex-col gap-1 rounded-md px-3 py-2"
                    >
                        <div className="flex flex-wrap items-center gap-2">
                            <VersiBadge versi={entri.versi} terbaru={entri.versi === 255} />
                            <StatusDokumenBadge status={entri.status_dokumen} />
                            <span className="text-muted-foreground text-xs tabular-nums">
                                {formatWaktu(entri.dibuat_at)}
                            </span>
                        </div>

                        <p className="text-sm">
                            {entri.keluhan_utama ?? '-'}
                        </p>

                        <p className="text-muted-foreground text-xs">
                            Diagnosis kerja: {entri.diagnosis_kerja ?? '-'}
                        </p>

                        <p className="text-muted-foreground text-xs">
                            Ditandatangani:{' '}
                            {entri.ditandatangani_at === null
                                ? 'belum'
                                : formatWaktu(entri.ditandatangani_at)}
                        </p>
                    </li>
                ))}
            </ol>
        </section>
    );
}

function Blok({ judul, isi }: { judul: string; isi: string | null }) {
    return (
        <div className="flex flex-col gap-1">
            <h3 className="text-sm font-semibold">{judul}</h3>

            <p className="text-sm whitespace-pre-wrap break-words">
                {isi === null || isi === '' ? '-' : isi}
            </p>
        </div>
    );
}

function Baris({ label, nilai }: { label: string; nilai: string }) {
    return (
        <div className="flex flex-col gap-0.5 sm:flex-row sm:gap-2">
            <span className="text-muted-foreground w-full text-xs sm:w-52">{label}</span>

            <span className="text-sm break-words">{nilai}</span>
        </div>
    );
}
