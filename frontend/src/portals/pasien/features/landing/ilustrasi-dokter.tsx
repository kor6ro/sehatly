import { cn } from '@/lib/utils';

/**
 * Kolase foto dokter untuk hero landing page.
 *
 * Strategi animasi entrance:
 *  - 4 strip bg-background menutup setiap kolom (25% lebar masing-masing).
 *  - Tiap strip slide ke atas (translateY -101%) satu per satu dengan delay bertahap.
 *  - Setelah semua strip pergi (~1050ms), animasi floating dimulai.
 *
 * File SVG: `/cokelat moderen model kolase foto.svg` (frontend/public/)
 */

export type DokterHeroItem = {
    foto: string | null;
    nama?: string;
    spesialisasi?: string;
    rating?: string;
};

export type IlustrasiDokterProps = {
    dokter?: readonly (DokterHeroItem | null)[];
    foto?: readonly (string | null)[];
    className?: string;
};

/** Delay antar strip (ms) — 4 kolom, kiri ke kanan */
const STRIP_DELAYS = [0, 160, 330, 500] as const;

export function IlustrasiDokter({ className }: IlustrasiDokterProps) {
    return (
        /**
         * Outer wrapper: float animation + drop-shadow
         * - animate-kolase-float mulai setelah strip selesai
         * - drop-shadow-2xl di luar agar tidak ter-clip oleh overflow-hidden dalam
         */
        <div
            className={cn(
                'relative w-full max-w-[580px] select-none',
                'animate-kolase-float drop-shadow-2xl',
                className,
            )}
        >
            {/**
             * Inner wrapper: overflow-hidden + rounded corner
             * Fungsi: mengclip strip saat slide ke atas agar tidak keliatan di atas gambar
             */}
            <div className="relative overflow-hidden rounded-2xl">
                {/* Gambar kolase asli */}
                <img
                    src="/cokelat moderen model kolase foto.svg"
                    alt="Kolase foto dokter profesional Sehatly"
                    className="block h-auto w-full"
                    loading="eager"
                    decoding="async"
                />

                {/* 4 strip penutup — slide ke atas satu per satu */}
                {STRIP_DELAYS.map((delay, i) => (
                    <div
                        key={i}
                        aria-hidden="true"
                        className="animate-strip-reveal pointer-events-none absolute inset-y-0 bg-background"
                        style={{
                            left: `${i * 25}%`,
                            width: '25.5%', /* sedikit overlap agar tidak ada celah */
                            animationDelay: `${delay}ms`,
                        }}
                    />
                ))}
            </div>
        </div>
    );
}
