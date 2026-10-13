import { Link } from 'react-router';

/**
 * One crumb. The last one carries no `to`: it is the page, not a way out of it.
 *
 * The doctor trail does not use that - both of its nodes are doors, because the heading
 * below it is what names the current page.
 */
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
 * The trail over the answer: `Home / Dokter Terdekat`.
 *
 * ## What replaced the two buttons
 *
 * The answer used to end in `Tutup` (with its X) and `Buka direktori lengkap`. Both are
 * this, phrased as places rather than as commands: `Home` is the way back to the whole
 * landing page, and `Dokter Terdekat` is this answer with nothing applied - the door out
 * of a specialisation, which is what `Buka direktori lengkap` was.
 *
 * `Home` and `Dokter Terdekat` are two different addresses on purpose. `/` is the front
 * page; `/?semua=1` is every doctor, nearest first, which is what a visitor arrives at
 * from the header and what a search or a specialisation can be cleared back to.
 * Collapsing them would mean that leaving a specialisation quietly threw the visitor back
 * to the marketing sections.
 *
 * ## It stops at two nodes, and the picked specialisation is NOT one of them
 *
 * It used to end in the chosen specialisation, which put the same words twice on one
 * screen: the `<h1>` above the answer already names what was picked, and a breadcrumb whose
 * last node restates the heading is a second caption for the same fact. The heading is the
 * louder of the two and carries the count underneath it, so the trail only needs to say
 * how the visitor got here - which is what a breadcrumb is for.
 *
 * ## It takes no arguments, because there is nothing left to read
 *
 * It used to look the specialisation's name up in `master-spesialisasi` in order to print
 * the node this version deleted. With that node gone, the two labels are constants and the
 * trail needs no request at all - one less thing that can fail while the answer beside it
 * is still loading.
 *
 * They were removable because neither was ever the only way out: the sticky bar above
 * carries the whole app. The trail is here to SAY where the visitor is, not to be the last
 * exit in the building.
 */
export function JejakDokter() {
    const butir: Array<Butir> = [
        { label: 'Home', to: '/' },
        { label: 'Dokter Terdekat', to: '/?semua=1' },
    ];

    return <Jejak butir={butir} />;
}
