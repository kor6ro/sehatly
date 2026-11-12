import { Link } from 'react-router';
import {
    Building2,
    CalendarCheck,
    ChevronDown,
    Clock,
    Info,
    MessageSquare,
    Stethoscope,
} from 'lucide-react';
import type { DokterDetail } from '@/lib/api/types';
import { labelTipeDokter } from '@/lib/api/dokter';
import { formatRupiah } from '@/lib/format';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { DoctorTrustBadge } from '@/portals/pasien/features/dokter-profil/doctor-trust-badge';

export function DoctorProfileHero({
    dokter,
    online,
}: {
    dokter: DokterDetail;
    online: boolean;
}) {
    const spesialisasiUtama = dokter.spesialisasi.find((row) => row.is_utama);
    const faskesUtama = dokter.faskes.find((row) => row.is_utama) ?? dokter.faskes[0];

    return (
        <Card data-slot="doctor-hero" className="overflow-hidden">
            {/* Banner gradient atas */}
            <div className="from-primary/20 to-primary/5 h-24 bg-gradient-to-br" />

            <CardContent className="flex flex-col gap-5 pt-0">
                {/* Avatar + nama — overlap banner */}
                <div className="-mt-12 flex flex-col gap-4 sm:flex-row sm:items-end">
                    <Avatar
                        aria-hidden
                        className="border-background size-24 rounded-xl border-4 shadow-md sm:size-28"
                    >
                        {dokter.foto_profil === null ? null : (
                            <AvatarImage src={dokter.foto_profil} alt="" />
                        )}
                        <AvatarFallback className="rounded-xl text-2xl font-bold">
                            {inisial(dokter.nama_lengkap)}
                        </AvatarFallback>
                    </Avatar>

                    <div className="flex min-w-0 flex-1 flex-col gap-1 pb-1">
                        <div className="flex flex-wrap items-center gap-2">
                            <h2 className="text-xl font-semibold tracking-tight">
                                {dokter.nama_lengkap}
                            </h2>
                            <DoctorTrustBadge status={dokter.status_verifikasi} />
                        </div>
                        <p className="text-muted-foreground text-sm">
                            {labelTipeDokter(dokter.tipe)}
                            {spesialisasiUtama?.nama == null
                                ? ' • Spesialisasi belum dicatat'
                                : ` • ${spesialisasiUtama.nama}`}
                        </p>
                    </div>
                </div>

                {/* Stat pills */}
                <div className="flex flex-wrap gap-2">
                    <span className="bg-muted flex items-center gap-1.5 rounded-full px-3 py-1 text-sm">
                        <Stethoscope aria-hidden className="text-primary size-3.5" />
                        {dokter.pengalaman_tahun === null
                            ? 'Pengalaman belum dicatat'
                            : `${String(dokter.pengalaman_tahun)} thn pengalaman`}
                    </span>
                    <span className="bg-muted flex items-center gap-1.5 rounded-full px-3 py-1 text-sm">
                        <MessageSquare aria-hidden className="text-primary size-3.5" />
                        {`${String(dokter.jumlah_konsultasi)} konsultasi`}
                    </span>
                    {dokter.durasi_default_menit !== null && (
                        <span className="bg-muted flex items-center gap-1.5 rounded-full px-3 py-1 text-sm">
                            <Clock aria-hidden className="text-primary size-3.5" />
                            {`${String(dokter.durasi_default_menit)} menit / sesi`}
                        </span>
                    )}
                </div>

                {/* Harga */}
                <div className="grid gap-3 sm:grid-cols-2">
                    <div className="bg-muted/50 rounded-lg p-3">
                        <p className="text-muted-foreground text-xs">Konsultasi online</p>
                        <p className="text-foreground mt-0.5 text-base font-bold tabular-nums">
                            {formatRupiah(dokter.biaya_konsultasi_online)}
                        </p>
                    </div>
                    <div className="bg-muted/50 rounded-lg p-3">
                        <p className="text-muted-foreground text-xs">Di luar jam</p>
                        <p className="text-foreground mt-0.5 text-base font-bold tabular-nums">
                            {formatRupiah(dokter.biaya_luar_jam)}
                        </p>
                    </div>
                </div>

                {/* Faskes */}
                <div className="flex flex-col gap-2">
                    <p className="text-sm">
                        <Building2
                            aria-hidden
                            className="text-muted-foreground mr-1.5 inline size-4"
                        />
                        {faskesUtama === undefined ? (
                            'Belum ada afiliasi'
                        ) : (
                            <>
                                {faskesUtama.nama ?? '-'}
                                {faskesUtama.alamat === null
                                    ? ''
                                    : ` • ${faskesUtama.alamat}`}
                            </>
                        )}
                    </p>

                    {dokter.faskes.length > 1 ? (
                        <Collapsible>
                            <CollapsibleTrigger asChild>
                                <button
                                    type="button"
                                    className="group focus-visible:ring-ring hover:bg-accent hover:text-accent-foreground flex min-h-11 w-fit items-center gap-2 rounded-md px-1 text-sm font-medium transition-colors focus-visible:ring-2 focus-visible:outline-none"
                                >
                                    Lihat semua fasilitas
                                    <ChevronDown
                                        aria-hidden
                                        className="size-4 transition-transform group-data-[state=open]:rotate-180"
                                    />
                                </button>
                            </CollapsibleTrigger>
                            <CollapsibleContent>
                                <ul className="flex flex-col gap-3 pt-3">
                                    {dokter.faskes.map((row) => (
                                        <li
                                            key={row.faskes_id}
                                            className="flex flex-col gap-0.5 text-sm"
                                        >
                                            <span className="font-medium">
                                                {row.nama ?? '-'}
                                            </span>
                                            <span className="text-muted-foreground">
                                                {row.alamat ?? 'Alamat belum dicatat'}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            </CollapsibleContent>
                        </Collapsible>
                    ) : null}
                </div>

                {/* CTA */}
                <div className="flex flex-col gap-3">
                    <Button
                        asChild
                        size="lg"
                        className="min-h-12 w-full sm:w-fit"
                        data-slot="cta-pesan-jadwal"
                        aria-disabled={!online}
                        aria-describedby={online ? undefined : 'dokter-alasan-offline'}
                        onClick={(event) => {
                            if (!online) event.preventDefault();
                        }}
                    >
                        <Link to={`/booking/${String(dokter.id)}`}>
                            <CalendarCheck aria-hidden />
                            Pesan jadwal
                        </Link>
                    </Button>

                    <p className="text-muted-foreground flex items-start gap-2 text-sm">
                        <Info aria-hidden className="mt-0.5 size-4 shrink-0" />
                        Nomor STR, SIP, dan kontak langsung dokter tidak dipublikasikan.
                        Hubungi dokter melalui pemesanan konsultasi.
                    </p>
                </div>
            </CardContent>
        </Card>
    );
}

function inisial(nama: string): string {
    const tanpaGelarDepan = nama.replace(
        /\b(dr|drg|ns|apt|prof|bidan|psikolog)\.?\s+/gi,
        '',
    );
    const kata = tanpaGelarDepan
        .split(/\s+/)
        .map((bagian) => bagian.replace(/[^\p{L}]/gu, ''))
        .filter((bagian) => bagian.length > 0);

    if (kata.length === 0) return nama.slice(0, 1).toUpperCase();
    return kata
        .slice(0, 2)
        .map((bagian) => bagian.slice(0, 1).toUpperCase())
        .join('');
}
