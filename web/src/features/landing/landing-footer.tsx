import { Link } from 'react-router';
import { Mail, Phone } from 'lucide-react';
import { FOOTER_KOLOM } from './data';

/**
 * The landing page's footer.
 *
 * ## Why every link is a registered route
 *
 * The three columns come from `FOOTER_KOLOM`, and each `to` in there is a path
 * `app/router.tsx` registers. A footer is where a site puts the links it is least sure
 * about - "Karir", "Tentang Kami" - and a dead link in the footer is discovered by
 * exactly the visitor who was trying to find something. Sehatly has no about page and no
 * careers page, so those slots do not exist rather than pointing at `#`.
 *
 * ## Why the contact block is `mailto:` and `tel:`
 *
 * Those are not routes and do not pretend to be: they hand off to the mail client and
 * the dialler, which is what the icon promises. They are the one place on this page where
 * leaving the SPA is the intended behaviour.
 */
export function LandingFooter() {
    return (
        <footer data-slot="landing-footer" className="border-border bg-card border-t">
            <div className="mx-auto grid w-full max-w-[1280px] gap-10 px-4 py-12 md:grid-cols-[minmax(0,20rem)_minmax(0,1fr)] md:px-6">
                <div className="flex flex-col gap-4">
                    <Link
                        to="/"
                        className="flex w-fit items-center gap-2"
                        aria-label="Sehatly - beranda"
                    >
                        <img
                            src="/logo.svg"
                            alt=""
                            aria-hidden="true"
                            className="size-8"
                        />

                        <span className="text-xl font-bold tracking-tight">Sehatly</span>
                    </Link>

                    <p className="text-muted-foreground max-w-sm text-sm leading-relaxed">
                        Layanan konsultasi, janji temu, dan resep elektronik untuk pasien
                        Indonesia. Masuk hanya dengan nomor ponsel - tanpa kata sandi.
                    </p>

                    <div className="text-muted-foreground flex flex-col gap-2 text-sm">
                        <a
                            href="mailto:halo@sehatly.test"
                            className="flex w-fit items-center gap-2 hover:text-foreground"
                        >
                            <Mail className="size-4" />
                            halo@sehatly.test
                        </a>

                        <a
                            href="tel:+62215095000"
                            className="flex w-fit items-center gap-2 hover:text-foreground"
                        >
                            <Phone className="size-4" />
                            021-5095-000
                        </a>
                    </div>
                </div>

                <div className="grid gap-8 sm:grid-cols-3">
                    {FOOTER_KOLOM.map((kolom) => (
                        <nav key={kolom.judul} aria-label={kolom.judul}>
                            <p className="text-primary mb-3 text-xs font-semibold tracking-wide uppercase">
                                {kolom.judul}
                            </p>

                            <ul className="flex flex-col gap-2.5">
                                {kolom.tautan.map((tautan) => (
                                    <li key={tautan.to}>
                                        <Link
                                            to={tautan.to}
                                            className="text-muted-foreground text-sm hover:text-foreground"
                                        >
                                            {tautan.label}
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </nav>
                    ))}
                </div>
            </div>

            <div className="border-border mx-auto w-full max-w-[1280px] border-t px-4 py-6 md:px-6">
                <p className="text-muted-foreground text-xs leading-relaxed">
                    &copy; {new Date().getFullYear()} Sehatly. Situs demonstrasi - isi,
                    nama tenaga kesehatan, dan harga yang tertera hanya contoh, bukan
                    layanan medis sungguhan.
                </p>
            </div>
        </footer>
    );
}
