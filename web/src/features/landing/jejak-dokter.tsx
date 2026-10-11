import { Link } from 'react-router';
import { useQuery } from '@tanstack/react-query';
import { spesialisasiOptions } from '@/lib/api/dokter';

/** One crumb. The last one carries no `to`: it is the page, not a way out of it. */
type Butir = { label: string; to?: string };

/**
 * The trail over anything the header can ask for: a list of crumbs, the current one
 * unlinked and marked `aria-current="page"`.
 *
 * A real `<nav aria-label>` around a real `<ol>`, because a stack of divs that merely
 * reads "Home / Spesialis Anak" is invisible to the one thing that most needs telling
 * where it is.
 */
export function Jejak({ butir }: { butir: ReadonlyArray<Butir> }) {
    const dasar =
        'text-muted-foreground hover:text-foreground underline-offset-4 hover:underline';

    return (
        <nav
            aria-label="Breadcrumb"
            data-slot="landing-krikib"
            className="bg-background w-full"
        >
            <ol className="mx-auto flex w-full max-w-[1280px] flex-wrap items-center gap-x-2 gap-y-1 px-4 py-3 text-sm md:px-6">
                {butir.map((item, index) => {
                    const terakhir = index === butir.length - 1;

                    return (
                        <li
                            key={item.label}
                            className={
                                terakhir ? 'flex items-center' : 'flex items-center gap-2'
                            }
                        >
                            {item.to === undefined ? (
                                <span
                                    aria-current="page"
                                    className="text-foreground font-medium"
                                >
                                    {item.label}
                                </span>
                            ) : (
                                <Link to={item.to} className={dasar}>
                                    {item.label}
                                </Link>
                            )}

                            {terakhir ? null : (
                                <span aria-hidden className="text-muted-foreground/50">
                                    /
                                </span>
                            )}
                        </li>
                    );
                })}
            </ol>
        </nav>
    );
}

/**
 * The trail over the directory: `Home / Direktori dokter / Spesialis Anak`.
 *
 * ## What replaced the two buttons
 *
 * The answer used to end in `Tutup` (with its X) and `Buka direktori lengkap`. Both are
 * this, phrased as places rather than as commands: `Home` is the way back to the whole
 * landing page, and `Direktori dokter` is this answer with nothing applied - the door out
 * of a filter, which is what `Buka direktori lengkap` was.
 *
 * `Home` and `Direktori dokter` are two different addresses on purpose. `/` is the front
 * page; `/?semua=1` is the directory with nothing applied, which is the state "Hapus
 * semua filter" and "Lihat semua dokter" both land on. Collapsing them would mean that
 * clearing the last filter quietly threw the visitor back to the marketing sections.
 *
 * They were removable because neither was ever the only way out: the sticky bar above
 * carries the whole app. The trail is here to SAY where the visitor is - which is what a
 * breadcrumb is for - not to be the last exit in the building.
 */
export function JejakDokter({ params }: { params: URLSearchParams }) {
    const master = useQuery(spesialisasiOptions());

    const kode = params.get('spesialisasi');
    const nama = (master.data?.data.spesialisasi ?? []).find(
        (row) => row.kode === kode,
    )?.nama;

    const butir: Array<Butir> = [
        { label: 'Home', to: '/' },
        { label: 'Direktori dokter', to: '/?semua=1' },
    ];

    if (kode !== null) {
        butir.push({ label: nama ?? kode });
    }

    return <Jejak butir={butir} />;
}
