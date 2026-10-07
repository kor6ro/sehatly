import {
    useEffect,
    useMemo,
    useRef,
    useState,
    type PointerEvent as ReactPointerEvent,
    type ReactNode,
} from 'react';
import { Link, NavLink, useNavigate } from 'react-router';
import { useMutation, useQuery } from '@tanstack/react-query';
import {
    ArrowRight,
    Bell,
    CalendarDays,
    ChevronDown,
    FileText,
    LayoutDashboard,
    LogOut,
    Menu,
    MessagesSquare,
    Pill,
    Search,
    Settings,
    Stethoscope,
    UserRound,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { logout } from '@/lib/api/auth';
import { cariMasterPopuler, POPULER, spesialisasiOptions } from '@/lib/api/dokter';
import { heroOptions } from '@/lib/api/hero';
import { meOptions } from '@/lib/api/me';
import { PROMO, SLIDE_HERO } from '@/features/landing/data';
import { IKON_BAWAAN, IKON_SPESIALISASI } from '@/features/landing/ikon-spesialis';
import { RelMenggulir } from '@/features/landing/rel-menggulir';
import { clearTokens, getRefreshToken } from '@/lib/token';
import { queryClient } from '@/lib/query-client';
import { dispatchFlash } from '@/lib/flash';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { ThemeToggle } from '@/components/layout/theme-toggle';
import { useSession } from '@/hooks/use-session';
import { cn } from '@/lib/utils';

/**
 * The header for `/` - the landing page, which is home to every visitor: signed out,
 * signed in as a patient, or signed in as a doctor.
 *
 * ## Why this is not `AppShell`'s header
 *
 * `AppShell` renders a sidebar and an account header, both of which need a signed-in
 * account to mean anything, and its sidebar would put a patient workspace in front of
 * somebody who has not asked for it. The landing page needs the opposite: a way in, a way
 * to browse the public directory, and - once there IS a session - the account's own name
 * instead of the button that used to be there. So this is a second, much smaller frame
 * rather than a conditional branch inside the shell.
 *
 * ## The shape is borrowed; the labels are ours
 *
 * The layout follows the Indonesian telemedicine convention this product competes in:
 * mark and wordmark on the left, a short nav of service entry points, one filled
 * call-to-action on the right. Two deliberate differences from the reference:
 *
 * - No endorsement badge. The reference shows a ministry-of-health endorsement. Claiming
 *   one here would be a statement of fact about Sehatly that nobody has made, so the slot
 *   simply does not exist in this component.
 * - Every menu entry points at a route that is registered in `app/router.tsx`. There are
 *   no placeholder links: a signed-out visitor who picks "Chat dengan Dokter" lands on
 *   `RequireAuth`, which hands them to `/login` - the correct outcome, reached through
 *   the router rather than through a link that goes nowhere.
 * - The nav shows its content before it asks for a click. Both entries with something
 *   behind them - "Direktori Dokter" and "Layanan Kesehatan" - expand under a pointer
 *   instead of waiting to be clicked, so the visitor can see what is on offer without
 *   already knowing where it leads. Nothing is reachable only that way: the same panels
 *   open on click and on Enter, and the mobile sheet keeps the links it had.
 *
 * ## Why the wordmark is text and not a second logo file
 *
 * `public/logo.svg` was the Laravel/React scaffold logo until this branch replaced it,
 * which is a mismatch that only shows up in production: `npm run dev` serves
 * `web/public/logo.svg` (the Sehatly mark) while Apache serves `public/logo.svg`. Both
 * paths now hold the same bytes, and the wordmark is rendered as text so that the name
 * in the header can never disagree with the name in `<title>` again.
 */
type NavItem = {
    to: string;
    label: string;
    description: string;
    icon: LucideIcon;
    /**
     * The column heading this entry sits under in the desktop mega panel.
     *
     * The sheet (touch) shows a flat list, so the field is only read on the bar - but it
     * lives on the item rather than on the panel because the two must not disagree: a
     * heading that groups a link on one surface and not the other is how a menu starts
     * telling two different stories.
     */
    kelompok: string;
};

/**
 * Service entry points, each resolving to a real route - the group order below is the
 * column order on the bar, because the groups are discovered in insertion order.
 *
 * "Pengingat Obat" is new here: the mega panel has room for the whole set the landing
 * body offers, and `/pengingat` was already one of its tiles.
 */
const LAYANAN: NavItem[] = [
    {
        to: '/konsultasi',
        label: 'Chat dengan Dokter',
        description: 'Konsultasi teks & video',
        icon: MessagesSquare,
        kelompok: 'Konsultasi & Janji',
    },
    {
        to: '/booking',
        label: 'Booking Janji Temu',
        description: 'Pilih jadwal dokter',
        icon: CalendarDays,
        kelompok: 'Konsultasi & Janji',
    },
    {
        to: '/pasien/resep',
        label: 'Resep & Apotek',
        description: 'Antar obat ke rumah',
        icon: Pill,
        kelompok: 'Obat & Apotek',
    },
    {
        to: '/pengingat',
        label: 'Pengingat Obat',
        description: 'Alarm minum obat',
        icon: Bell,
        kelompok: 'Obat & Apotek',
    },
    {
        to: '/rekam-medis',
        label: 'Rekam Medis',
        description: 'Riwayat kesehatan Anda',
        icon: FileText,
        kelompok: 'Riwayat Kesehatan',
    },
];

/** The one nav entry that needs no session at all. */
const DIREKTORI = '/dokter';

function navLinkClass({ isActive }: { isActive: boolean }): string {
    return cn(
        'inline-flex items-center rounded-lg px-3 py-2 text-[15px] font-medium transition-colors',
        isActive
            ? 'bg-secondary text-primary'
            : 'text-foreground/75 hover:bg-secondary hover:text-primary',
    );
}

/**
 * Hover intent for the two desktop nav entries that open something.
 *
 * ## Why the delay is on CLOSE and not on OPEN
 *
 * Opening on the first pointer frame is what makes a hover menu feel instant; the grace
 * period is what keeps it from feeling nervous. The pointer crosses a seam between a
 * trigger and its panel, and without ~160 ms of slack the panel would flicker shut and
 * reopen on that seam. Moving away for real still closes it one beat later, which nobody
 * perceives as a delay.
 *
 * ## Why `pointerType === 'mouse'`
 *
 * A touch pointer fires `pointerenter` on TAP, so treating every pointer alike would
 * open the panel under a finger that was pressing a link - and the tap would then be
 * spent inside a panel nobody asked for. Touch keeps click-to-open exactly as it was.
 * Nothing in this header is reachable ONLY by hover, which is the rule that keeps the
 * keyboard and the phone working.
 *
 * ## Why the source is remembered
 *
 * A click on a trigger the pointer is already resting on must not close the menu from
 * under the cursor (there is no pointer re-entry that would bring it back), while Escape
 * and an outside click must still close it. `sumber` is the difference: while a hover
 * owns the menu and the pointer is inside, other close paths are refused; every other
 * path closes normally.
 */
function useNavHover() {
    const [open, setOpen] = useState(false);
    /** True while a MOUSE pointer is inside the trigger or the panel it opened. */
    const diDalam = useRef(false);
    /** `'hover'` while hover is what opened the menu; `null` for click, touch or focus. */
    const sumber = useRef<'hover' | null>(null);
    const timer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);

    useEffect(() => () => clearTimeout(timer.current), []);

    const batalkan = () => clearTimeout(timer.current);

    const onPointerEnter = (e: ReactPointerEvent<HTMLElement>) => {
        if (e.pointerType !== 'mouse') return;

        diDalam.current = true;
        sumber.current = 'hover';
        batalkan();
        setOpen(true);
    };

    const onPointerLeave = (e: ReactPointerEvent<HTMLElement>) => {
        if (e.pointerType !== 'mouse') return;

        diDalam.current = false;
        sumber.current = null;
        batalkan();
        timer.current = setTimeout(() => setOpen(false), 160);
    };

    /** Close at once: Escape, a blur out of the whole entry, or a link that navigates. */
    const tutup = () => {
        diDalam.current = false;
        sumber.current = null;
        batalkan();
        setOpen(false);
    };

    return {
        open,
        setOpen,
        diDalam,
        sumber,
        onPointerEnter,
        onPointerLeave,
        tutup,
        batalkan,
    };
}

/** What a panel receives so its own links can close it after they navigate. */
type NavHover = ReturnType<typeof useNavHover>;

/**
 * The shared skeleton of a desktop nav entry that opens a panel under the pointer.
 *
 * ## Why this path does not go near Radix
 *
 * A hover-driven menu cannot be built on `DropdownMenu`, and the failure was not
 * theoretical - it shipped and was reported as "auto buka tutup terus": Radix's menu is
 * MODAL by default, and opening it writes `pointer-events: none` onto `document.body`
 * so that only the portalled content can be clicked. The trigger the cursor is resting
 * on is then no longer hoverable, the browser re-evaluates hover and fires
 * `pointerleave`, {@link useNavHover} closes the menu a beat later, Radix clears the
 * inline style on close, hover is re-evaluated again and `pointerenter` reopens it -
 * a ~300 ms open/close loop that no amount of grace period can absorb, because the
 * menu itself is what makes the pointer leave.
 *
 * The fix is structural rather than a longer delay: on the bar the panel is an ordinary
 * DOM child of the wrapper, nothing is portalled, and no library touches the page's
 * pointer-events - so the pointer that opened the menu is still over it. Radix keeps the
 * job it was always right for, the sheet in {@link NavDropdownLaci}, where the menu is
 * opened by a tap and hover does not exist to fight with.
 *
 * ## The rules, which both panels inherit
 *
 * - A mouse opens it; `pointerenter` from a touch pointer is ignored, because on a phone
 *   the tap IS the click and it must not be spent opening a panel nobody asked for.
 * - Focus opens it too, Escape closes it and hands focus back to the trigger (focus
 *   first, then close - the other order reopens what was just dismissed), and a blur
 *   out of the whole entry closes it.
 * - A click toggles only when no mouse is hovering: while the pointer is inside there is
 *   no re-entry that could bring it back, so a click there would be a one-way door.
 */
function NavPanelEntry({
    label,
    idPanel,
    panel,
}: {
    label: string;
    /** The `aria-controls` target, which only exists while the panel is open. */
    idPanel: string;
    panel: (hover: NavHover) => ReactNode;
}) {
    const hover = useNavHover();
    const trigger = useRef<HTMLButtonElement>(null);

    return (
        <div
            onPointerEnter={hover.onPointerEnter}
            onPointerLeave={hover.onPointerLeave}
            onFocusCapture={() => {
                if (!hover.open) hover.setOpen(true);
            }}
            onBlurCapture={(event) => {
                if (!event.currentTarget.contains(event.relatedTarget)) hover.tutup();
            }}
            onKeyDown={(event) => {
                if (event.key === 'Escape') {
                    // Focus BEFORE closing: `tutup()` sets `open` false, and opening the
                    // panel on focus is what `onFocusCapture` above does - closing first
                    // and focusing second would hand the panel straight back to the
                    // visitor who just dismissed it.
                    trigger.current?.focus();
                    hover.tutup();
                }
            }}
        >
            <button
                ref={trigger}
                type="button"
                aria-expanded={hover.open}
                aria-controls={idPanel}
                className={navLinkClass({ isActive: false })}
                onClick={() => {
                    // A mouse is already inside, and hover has already opened it; for a
                    // finger or a keyboard this is the only way in, so it toggles.
                    if (hover.diDalam.current) return;
                    hover.setOpen((nilai) => !nilai);
                }}
            >
                {label}
                <ChevronDown
                    className={cn(
                        'size-4 opacity-60 transition-transform',
                        hover.open && 'rotate-180',
                    )}
                />
            </button>

            {hover.open ? panel(hover) : null}
        </div>
    );
}

/**
 * The promo card the mega panels carry, which is where their pictures come from.
 *
 * `GET /hero` is the product's only public source of real photography, and it is the
 * admin's: a slide is written in `/admin/hero` with its image and its alt text, so the
 * menu's pictures change without a deploy for the same reason the carousel's do. Both
 * surfaces ask for the same query key, so the header costs no extra request on the
 * landing page - the carousel has already fetched it.
 *
 * The gallery is allowed to be empty, and this renders NOTHING rather than a grey box or
 * a caption without a picture: a menu that promises an image it cannot show is worse
 * than a menu that simply has no pictures today. The card is a link to the slide's own
 * `cta_target`, which the server only accepts as an internal path.
 */
function KartuPromo({ onPilih }: { onPilih?: () => void }) {
    const hero = useQuery(heroOptions());
    const bergambar = (hero.data?.data.hero ?? []).filter(
        (slide): slide is typeof slide & { gambar: string } => slide.gambar !== null,
    );

    if (bergambar.length === 0) return null;

    return (
        <div className="grid gap-3">
            {bergambar.slice(0, 2).map((slide) => (
                <Link
                    key={slide.id}
                    to={slide.cta_target}
                    onClick={onPilih}
                    data-slot="nav-promo"
                    className="border-border hover:border-primary/40 group/promo block overflow-hidden rounded-xl border transition-colors"
                >
                    <img
                        src={slide.gambar}
                        alt={slide.gambar_alt ?? ''}
                        loading="lazy"
                        className="h-32 w-full object-cover"
                    />

                    <span className="block p-3">
                        <span className="block text-sm leading-snug font-semibold">
                            {slide.judul}
                        </span>

                        <span className="text-primary mt-1 inline-flex items-center gap-1 text-xs font-semibold">
                            {slide.cta_label}
                            <ArrowRight className="size-3.5" />
                        </span>
                    </span>
                </Link>
            ))}
        </div>
    );
}

/** Items grouped by their column heading, in the order the groups first appear. */
function kelompokkan(items: readonly NavItem[]): Array<{
    nama: string;
    isi: NavItem[];
}> {
    const peta = new Map<string, NavItem[]>();

    for (const item of items) {
        const ada = peta.get(item.kelompok);
        if (ada) ada.push(item);
        else peta.set(item.kelompok, [item]);
    }

    return [...peta].map(([nama, isi]) => ({ nama, isi }));
}

/**
 * "Layanan Kesehatan" as a Zalora-shaped panel: group headings, plain text links (the
 * bar is for scanning; the icon-and-description treatment belongs to the sheet, where
 * there is room to breathe and no columns to fill), and the promo card down the right
 * hand side.
 *
 * Every link is a route from `LAYANAN`, which is registered in `app/router.tsx` - a
 * signed-out visitor who picks one still lands on `RequireAuth` and its `/login`, the
 * correct outcome reached through the router.
 */
function PanelLayanan({ items }: { items: NavItem[] }) {
    const kolom = kelompokkan(items);

    return (
        <div
            id="nav-layanan-panel"
            data-slot="nav-layanan-panel"
            className="bg-popover text-popover-foreground border-border absolute top-full right-0 left-0 z-50 rounded-b-2xl border p-5 shadow-xl"
        >
            <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_18rem]">
                <div className="grid gap-x-6 gap-y-6 sm:grid-cols-2 lg:grid-cols-3">
                    {kolom.map((kelompok) => (
                        <div key={kelompok.nama} className="min-w-0">
                            <p className="text-muted-foreground mb-2 text-xs font-semibold tracking-wide uppercase">
                                {kelompok.nama}
                            </p>

                            <ul className="grid gap-0.5">
                                {kelompok.isi.map((item) => (
                                    <li key={item.to}>
                                        <Link
                                            to={item.to}
                                            className="hover:bg-secondary hover:text-primary block rounded-md px-2 py-2 text-sm font-medium"
                                        >
                                            {item.label}
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ))}
                </div>

                <div className="min-w-0">
                    <p className="text-muted-foreground mb-2 text-xs font-semibold tracking-wide uppercase">
                        Promo
                    </p>

                    <KartuPromo />
                </div>
            </div>
        </div>
    );
}

/**
 * The bar's entry (hover panel) and the sheet's entry (Radix dropdown).
 *
 * Splitting them is the point: `interaksi === 'hover'` must not mount Radix at all - see
 * {@link NavPanelEntry} for the measured reason - while `sentuh` keeps exactly the menu
 * it had.
 */
function NavLayanan({
    label,
    items,
    onNavigate,
    interaksi,
}: {
    label: string;
    items: NavItem[];
    /** Closes the mobile sheet, where the same list renders inline. */
    onNavigate?: () => void;
    interaksi: 'hover' | 'sentuh';
}) {
    if (interaksi === 'hover') {
        return (
            <NavPanelEntry
                label={label}
                idPanel="nav-layanan-panel"
                panel={() => <PanelLayanan items={items} />}
            />
        );
    }

    return (
        <NavDropdownLaci label={label} items={items} onNavigate={onNavigate} />
    );
}

/**
 * The sheet's dropdown: click-to-open, portalled, no hover anywhere.
 *
 * `asChild` puts Radix's `data-state` on the `<Button>`, which is what the rotated
 * chevron keys off (`group-data-[state=open]:rotate-180`) - so the arrow turns with the
 * menu without a second source of truth for "is this open?".
 *
 * It is deliberately uncontrolled here. The previous version had to hold Radix's `open`
 * to serve a hover that this surface does not have, and fighting a modal menu's
 * pointer-events lockout from the outside is a war of attrition - see
 * {@link NavPanelEntry}. Inside a sheet there is no pointer to strand, so Radix can own
 * its own state as it was designed to.
 */
function NavDropdownLaci({
    label,
    items,
    onNavigate,
}: {
    label: string;
    items: NavItem[];
    onNavigate?: () => void;
}) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    className="group gap-1 px-3 text-[15px] font-medium text-foreground/75 hover:bg-secondary hover:text-primary"
                >
                    {label}
                    <ChevronDown className="size-4 opacity-60 transition-transform group-data-[state=open]:rotate-180" />
                </Button>
            </DropdownMenuTrigger>

            <DropdownMenuContent align="start" className="w-72 p-1.5">
                <DropdownMenuLabel className="text-muted-foreground px-2 py-1.5 text-xs font-medium">
                    {label}
                </DropdownMenuLabel>

                {items.map((item) => (
                    <DropdownMenuItem key={item.to} asChild>
                        <Link
                            to={item.to}
                            onClick={onNavigate}
                            className="gap-3 px-2 py-2.5"
                        >
                            <span className="bg-secondary flex size-8 shrink-0 items-center justify-center rounded-md">
                                <item.icon className="text-primary size-4" />
                            </span>

                            <span className="flex min-w-0 flex-col">
                                <span className="truncate text-sm font-medium">
                                    {item.label}
                                </span>

                                <span className="text-muted-foreground truncate text-xs">
                                    {item.description}
                                </span>
                            </span>
                        </Link>
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/**
 * The directory's panel, in the Zalora desktop-menu shape: three zones side by side
 * under the title row - a scrolling icon rail of every specialisation, two columns of
 * shortcuts, and a grid of pictures.
 *
 * ## Why it stopped being a link on the desktop
 *
 * As a `<NavLink>` the first pointer-down on this entry threw the visitor onto `/dokter`
 * - search, filters, result cards - before they knew what the directory held: the menu
 * WAS the destination instead of being the choice. Hovering now reveals the choices
 * first. The rules that keep it usable without a pointer (focus, Escape, the sheet) are
 * {@link NavPanelEntry}'s, not repeated here.
 *
 * ## Why the panel spans the bar instead of hugging the trigger
 *
 * It is a child of the entry's wrapper - so `pointerleave` does not fire while the
 * pointer moves into it - but the wrapper carries NO `position: relative`, which makes
 * the header bar its containing block. The bar's width is guaranteed (it is
 * `max-w-[1280px]` and centered); a width measured from the trigger has to guess how
 * much room is left and guesses wrong on a narrow window.
 *
 * ## What each zone is made of, and why it is not a costume
 *
 * - **The rail (left) is the reference table itself**, all sixteen rows, icon + full
 *   name, in the order `GET /master-spesialisasi` returns them. It is a rail rather than
 *   the old four-column list because the shape was asked for by reference - but the
 *   reason it is allowed to be one is that sixteen names in a single column is the only
 *   arrangement where "Spesialis Orthopaedi & Traumatologi" is never abbreviated: the
 *   columns it used to sit in were as wide as the LONGEST name in each one.
 *   {@link RelMenggulir} scrolls it and draws its own thumb, because a rail whose ten
 *   visible rows are not followed by a visible scrollbar reads as a finished list.
 * - **The middle is two columns of shortcuts**: "Sering dicari" resolves `POPULER`
 *   against the very table the rail just read, so a shortcut whose code no longer exists
 *   is skipped rather than offered against a filter that returns nothing; "Layanan" is
 *   the same `LAYANAN` the landing page publishes, so the two can never tell a visitor
 *   different stories about what the app does.
 * - **The pictures (right) are the panel's photography**, and `GET /hero` is the
 *   product's only public source of it: an admin's slide, with its own image and alt
 *   text, comes first and keeps `data-slot="nav-promo"` so the picture is still a link to
 *   the campaign. The grid is SIX tiles - the reference menu's 3x2, not a ragged row - so
 *   it is topped up from the product's own campaign copy: `PROMO` (the section's four
 *   cards) and then `SLIDE_HERO` (the three slides the carousel shows when no gallery has
 *   been published). Both are editorial data with a `to` that resolves in
 *   `app/router.tsx`, so a filled cell is still never a fake photograph, and the moment
 *   the admin uploads enough slides the photographs take the six slots back. Same query
 *   key as the carousel, so the header costs no extra request on `/`.
 *
 * Every row and card resolves to a route in `app/router.tsx`, and every link closes the
 * panel on the way out (`hover.tutup`) - a menu that leaves itself open behind the page
 * it just opened is a menu the visitor has to dismiss twice.
 */
function PanelDirektori({ hover }: { hover: NavHover }) {
    const spesialisasi = useQuery(spesialisasiOptions());
    const hero = useQuery(heroOptions());
    const daftar = spesialisasi.data?.data.spesialisasi ?? [];

    /** One tile of the picture grid: a photograph when there is one, a painted card
     * when there is not - the same bargain the hero carousel makes, so the panel never
     * promises a picture it cannot show. */
    const kartu = useMemo<
        Array<{
            kunci: string;
            judul: string;
            tautan: string;
            to: string;
            foto: string | null;
            alt: string;
            gradien: string;
        }>
    >(() => {
        const foto = (hero.data?.data.hero ?? [])
            .filter(
                (slide): slide is typeof slide & { gambar: string } =>
                    slide.gambar !== null,
            )
            .map((slide) => ({
                kunci: `hero-${slide.id}`,
                judul: slide.judul,
                tautan: slide.cta_label,
                to: slide.cta_target,
                foto: slide.gambar as string,
                alt: slide.gambar_alt ?? '',
                gradien: '',
            }));

        const lukisan = [
            ...PROMO.map((promo) => ({
                kunci: promo.judul,
                judul: promo.judul,
                tautan: promo.cta,
                to: promo.to,
                foto: null,
                alt: '',
                gradien: promo.gradien,
            })),
            // Top-up only: these three are the slides the CAROUSEL falls back to, and
            // they are consulted because four PROMO cards leave the 3x2 grid a cell
            // short - never as a substitute for a photograph that does exist.
            ...SLIDE_HERO.map((slide) => ({
                kunci: `hero-bawaan-${slide.id}`,
                judul: slide.judul,
                tautan: slide.cta.label,
                to: slide.cta.to,
                foto: null,
                alt: '',
                gradien: slide.gradien,
            })),
        ];

        return [...foto, ...lukisan].slice(0, 6);
    }, [hero.data]);

    return (
        <div
            id="nav-direktori-panel"
            data-slot="nav-direktori-panel"
            className="bg-popover text-popover-foreground border-border absolute top-full right-0 left-0 z-50 rounded-b-2xl border p-5 shadow-xl"
        >
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <p className="text-sm font-semibold">Direktori Dokter</p>

                    <p className="text-muted-foreground text-sm">
                        Pilih bidang, lalu lihat jadwal praktik dan ulasan pasien.
                    </p>
                </div>

                <Link
                    to={DIREKTORI}
                    onClick={hover.tutup}
                    className="text-primary inline-flex shrink-0 items-center gap-1 text-sm font-semibold hover:underline"
                >
                    Lihat semua dokter
                    <ArrowRight className="size-4" />
                </Link>
            </div>

            <div
                data-slot="nav-direktori-zona"
                className="mt-4 grid items-start gap-6 lg:grid-cols-[minmax(0,15rem)_minmax(0,23rem)_minmax(0,1fr)] lg:gap-7"
            >
                {/* Zone 1 - the rail: the whole table, one row per specialisation */}
                <div className="lg:border-border min-w-0 lg:border-r lg:pr-6">
                    <p className="text-muted-foreground mb-2 text-xs font-semibold tracking-wide uppercase">
                        Spesialisasi
                    </p>

                    {spesialisasi.isPending ? (
                        <div className="grid gap-1">
                            {Array.from({ length: 8 }, (_, i) => (
                                <Skeleton key={i} className="h-10 rounded-lg" />
                            ))}
                        </div>
                    ) : null}

                    {spesialisasi.isError ? (
                        <p className="text-muted-foreground text-sm">
                            Daftar spesialis tidak dapat dimuat.{' '}
                            <Link
                                to={DIREKTORI}
                                onClick={hover.tutup}
                                className="text-primary font-medium"
                            >
                                Buka direktori dokter
                            </Link>{' '}
                            untuk memilih langsung.
                        </p>
                    ) : null}

                    {spesialisasi.isSuccess ? (
                        <RelMenggulir
                            slot="nav-direktori-spesialisasi"
                            label="Daftar spesialisasi, menggulir"
                            className="max-h-[22rem] lg:max-h-[28rem]"
                        >
                            {daftar.map((baris) => {
                                const Ikon =
                                    IKON_SPESIALISASI[baris.kode] ?? IKON_BAWAAN;

                                return (
                                    <li key={baris.kode}>
                                        <Link
                                            to={`/dokter?spesialisasi=${encodeURIComponent(baris.kode)}`}
                                            onClick={hover.tutup}
                                            className="hover:bg-secondary flex items-center gap-3 rounded-lg px-2.5 py-2.5 transition-colors"
                                        >
                                            <Ikon
                                                aria-hidden="true"
                                                className="text-foreground/80 size-4 shrink-0"
                                            />

                                            <span className="min-w-0 flex-1 text-sm leading-snug font-medium">
                                                {baris.nama}
                                            </span>
                                        </Link>
                                    </li>
                                );
                            })}
                        </RelMenggulir>
                    ) : null}
                </div>

                {/* Zone 2 - two columns of shortcuts, the same data the body of the
                    landing page publishes, laid out as the reference menu lays its two */}
                <div
                    data-slot="nav-direktori-pintasan"
                    className="grid min-w-0 gap-5 sm:grid-cols-2"
                >
                    <div className="min-w-0">
                        <p className="text-foreground flex items-center gap-1.5 text-xs font-semibold tracking-wide uppercase">
                            <Search aria-hidden="true" className="size-3.5" />
                            Sering dicari
                        </p>

                        <ul className="mt-1.5 grid gap-0.5">
                            {POPULER.map((pintasan) => {
                                const master = cariMasterPopuler(
                                    daftar,
                                    pintasan.kataKunci,
                                );

                                // Absent from the reference table = not offered, rather
                                // than offered against a code that no longer exists.
                                if (master === undefined) return null;

                                return (
                                    <li key={pintasan.label}>
                                        <Link
                                            to={`/dokter?spesialisasi=${encodeURIComponent(master.kode)}`}
                                            onClick={hover.tutup}
                                            className="hover:bg-secondary text-muted-foreground hover:text-foreground block rounded-md px-2 py-1.5 text-sm transition-colors"
                                        >
                                            {pintasan.label}
                                        </Link>
                                    </li>
                                );
                            })}
                        </ul>
                    </div>

                    <div className="min-w-0">
                        <p className="text-foreground flex items-center gap-1.5 text-xs font-semibold tracking-wide uppercase">
                            <Stethoscope aria-hidden="true" className="size-3.5" />
                            Layanan
                        </p>

                        <ul className="mt-1.5 grid gap-0.5">
                            {LAYANAN.map((layanan) => (
                                <li key={layanan.to}>
                                    <Link
                                        to={layanan.to}
                                        onClick={hover.tutup}
                                        className="hover:bg-secondary text-muted-foreground hover:text-foreground block rounded-md px-2 py-1.5 text-sm transition-colors"
                                    >
                                        {layanan.label}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </div>
                </div>

                {/* Zone 3 - the picture grid, which is where the panel's photographs
                    live; no heading, because the reference menu has none over its tiles */}
                <div className="min-w-0">
                    <div
                        data-slot="nav-direktori-promo"
                        className="grid grid-cols-2 gap-2.5 xl:grid-cols-3"
                    >
                        {kartu.map((kartu_) =>
                            kartu_.foto !== null ? (
                                <Link
                                    key={kartu_.kunci}
                                    to={kartu_.to}
                                    onClick={hover.tutup}
                                    data-slot="nav-promo"
                                    className="group relative block aspect-[4/3] overflow-hidden rounded-lg"
                                >
                                    <img
                                        src={kartu_.foto}
                                        alt={kartu_.alt}
                                        loading="lazy"
                                        className="absolute inset-0 size-full object-cover transition-transform duration-300 group-hover:scale-105"
                                    />

                                    <span
                                        aria-hidden="true"
                                        className="absolute inset-0 bg-gradient-to-t from-black/60 via-black/10 to-transparent"
                                    />

                                    <span className="absolute inset-x-2.5 bottom-2.5 text-white">
                                        <span className="block text-xs leading-snug font-semibold">
                                            {kartu_.judul}
                                        </span>

                                        <span className="mt-0.5 inline-flex items-center gap-1 text-[11px] font-medium opacity-90">
                                            {kartu_.tautan}
                                            <ArrowRight className="size-3" />
                                        </span>
                                    </span>
                                </Link>
                            ) : (
                                <Link
                                    key={kartu_.kunci}
                                    to={kartu_.to}
                                    onClick={hover.tutup}
                                    className="group relative block aspect-[4/3] overflow-hidden rounded-lg"
                                >
                                    <span
                                        aria-hidden="true"
                                        className={cn(
                                            'absolute inset-0 bg-gradient-to-br',
                                            kartu_.gradien,
                                        )}
                                    />

                                    <span
                                        aria-hidden="true"
                                        className="absolute inset-0 bg-gradient-to-t from-black/45 to-transparent"
                                    />

                                    <span className="absolute inset-x-2.5 bottom-2.5 text-white">
                                        <span className="block text-xs leading-snug font-semibold">
                                            {kartu_.judul}
                                        </span>

                                        <span className="mt-0.5 inline-flex items-center gap-1 text-[11px] font-medium opacity-90">
                                            {kartu_.tautan}
                                            <ArrowRight className="size-3" />
                                        </span>
                                    </span>
                                </Link>
                            ),
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}

/** The entry itself: a plain link in the sheet, a {@link NavPanelEntry} on the bar. */
function NavDirektori({
    interaksi,
    onNavigate,
}: {
    interaksi: 'hover' | 'sentuh';
    onNavigate?: () => void;
}) {
    if (interaksi === 'sentuh') {
        return (
            <NavLink to={DIREKTORI} className={navLinkClass} onClick={onNavigate}>
                Direktori Dokter
            </NavLink>
        );
    }

    return (
        <NavPanelEntry
            label="Direktori Dokter"
            idPanel="nav-direktori-panel"
            panel={(hover) => <PanelDirektori hover={hover} />}
        />
    );
}

/**
 * The full nav, rendered inline on desktop and inside the sheet on mobile.
 *
 * ## Why there is no "Untuk Dokter" menu
 *
 * The practitioner entrances (`/dokter/dashboard`, `/dokter/booking`) and the whole
 * `/admin` group are being kept out of the public face until they get a dedicated door
 * of their own: what the landing page shows is the PATIENT product. The routes are not
 * deleted, only unadvertised - they stay registered in `app/router.tsx` and still answer
 * the `tipe:`/`permission:` guards the server puts on them, so `/admin/hero` opens by URL
 * today and a labelled entrance can be re-added later without touching a route.
 *
 * The three entries it does carry: the directory (the funnel), the services dropdown,
 * and one anchor into this page's own "Cek Kesehatan Mandiri" section.
 *
 * ## `interaksi`: hover on the bar, tap in the sheet
 *
 * The same list renders twice - `hidden lg:flex` at the top of the page and inside the
 * mobile `Sheet` - and only one of those has a pointer that can hover. `hover` gives the
 * bar the panels that open themselves; `sentuh` keeps the sheet on links and click-to-
 * open menus, so a finger never opens something it cannot put away.
 */
function NavList({
    className,
    onNavigate,
    interaksi,
}: {
    className?: string;
    onNavigate?: () => void;
    interaksi: 'hover' | 'sentuh';
}) {
    return (
        <nav
            aria-label="Navigasi utama"
            className={cn('flex items-center gap-1', className)}
        >
            <NavDirektori interaksi={interaksi} onNavigate={onNavigate} />

            <NavLayanan
                label="Layanan Kesehatan"
                items={LAYANAN}
                onNavigate={onNavigate}
                interaksi={interaksi}
            />

            {/**
             * The nav's only in-page link, and the reason it is not a route.
             *
             * "Cek Kesehatan Mandiri" is a section of tiles with nothing to press
             * behind it - no endpoint, no screen, so a `/cek-mandiri` URL would be a
             * registered destination that resolves to nothing. An anchor to the section
             * on this very page carries the same meaning and adds no route to
             * `app/router.tsx`, which is also why the route census does not see it.
             *
             * A plain `<a>`, not a `NavLink`: `navLinkClass({ isActive: false })` gives
             * it the resting style the other two entries use, and the active style
             * would stay lit for a link whose hash changes no path for React Router to
             * match on.
             */}
            <a
                href="#cek-mandiri"
                onClick={onNavigate}
                className={navLinkClass({ isActive: false })}
            >
                Cek Kesehatan Mandiri
            </a>
        </nav>
    );
}

/**
 * The right-hand action cluster.
 *
 * The single decision it makes is what the visitor is here to do: nobody has a session,
 * so the action is "Masuk"; somebody does, and it collapses into the account pill below.
 *
 * ## Why this is a hook and not `getAccessToken() !== null`
 *
 * A plain read during render is correct and permanently stale - the token is written by
 * the OTP step and by `clearTokens()`, neither of which renders anything. Two screens
 * broke that way: a visitor who signed in through the dialog on this very page kept the
 * "Masuk" button, and a visitor who signed out left the pill's `/me` query subscribed,
 * so its refetch fired with no credentials and the transport, reading that as a session
 * expiry, moved them to `/login`. `useSession` subscribes to the store instead, which
 * makes both directions a re-render of exactly this component.
 *
 * There is deliberately no "Daftar" button here, and "Masuk" opens a dialog rather than
 * navigating: the bar offers one door, which is how the front page this header is modelled
 * on behaves, and a visitor without an account meets "Nomor belum terdaftar" inside that
 * dialog with the one action that fixes it. `onMasuk` is handed in rather than this
 * component owning a dialog, because it renders twice - top bar and mobile sheet - and two
 * instances would each hold their own challenge and race over `sessionStorage`.
 */
function ActionButtons({
    className,
    onMasuk,
}: {
    className?: string;
    onMasuk: () => void;
}) {
    const authenticated = useSession();

    if (authenticated) {
        return (
            <div className={cn('flex items-center gap-2', className)}>
                <AkunPill />
            </div>
        );
    }

    return (
        <div className={cn('flex items-center gap-2', className)}>
            <Button onClick={onMasuk} className="rounded-lg font-medium">
                Masuk
            </Button>
        </div>
    );
}

/** The two initials a name contributes: the first letters of its first two words. */
function inisial(nama: string): string {
    return nama
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((kata) => kata.charAt(0).toUpperCase())
        .join('');
}

/**
 * The signed-in half of {@link ActionButtons}: avatar, name, gear, chevron.
 *
 * ## Why a pill and not the "Dashboard" button it replaces
 *
 * The landing page is now the home every account returns to after signing in, so its
 * header has to answer "who am I?" rather than "where do I go?" - the same reason the
 * reference front page shows a name and a gear instead of a call to action. The workspace
 * is one menu entry away instead of being the whole bar, and a visitor who has not signed
 * in still sees the one button they need.
 *
 * ## Why `Profil` is conditional and `Dasbor` is not
 *
 * `GET /pasien/profil` answers 403 to every account without a `pasien` row, so offering
 * it to a doctor would render a link to a refusal. `Dasbor` is the one screen every
 * `users.tipe` can open - the sidebar behind it is simply different per role - which is
 * the same rule `AppShell` follows: a control that may not be used is not rendered.
 *
 * ## Why `/me` is a query and not a prop
 *
 * The header has no parent that knows the account: it renders on `/`, outside `AppShell`
 * and outside `RequireAuth`. `meOptions()` shares its cache key with every other reader of
 * "who am I", so the name here is the same object the dashboard greets with, and signing
 * out clears it along with everything else.
 */
function AkunPill() {
    const navigate = useNavigate();
    const adaSesi = useSession();

    /**
     * `enabled` keyed on the session, never `true`.
     *
     * The parent unmounts this pill the moment `clearTokens()` announces, but the two
     * updates race: if this component renders once in between, a query that refetches on
     * an empty cache would call `/me` with no `Authorization` header, and the transport
     * answers that 401 by declaring the session expired and moving the page to `/login` -
     * which is how signing OUT of the landing page used to land you on the sign-IN
     * screen. Gated on the session, that refetch cannot start at all.
     */
    const me = useQuery({ ...meOptions(), enabled: adaSesi });

    const user = me.data?.data.user ?? null;
    const nama = user?.nama_lengkap ?? '';
    const isPasien = user?.tipe === 'pasien';

    const signOut = useMutation({
        mutationFn: async () => {
            const refreshToken = getRefreshToken();

            if (refreshToken === null) {
                return null;
            }

            return logout(refreshToken);
        },
        /**
         * `onSettled`, not `onSuccess`, for the reason `AppShell` gives: the point of this
         * branch is the local pair. A sign-out the server rejects must still remove the
         * token from this browser, and it must land the visitor on the landing page
         * either way - where, without a token, the pill is replaced by "Masuk".
         */
        onSettled: () => {
            clearTokens();
            queryClient.clear();

            dispatchFlash({ level: 'info', message: 'Anda telah keluar.' });

            void navigate('/', { replace: true });
        },
    });

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    data-slot="landing-akun"
                    className="border-border bg-background hover:bg-secondary flex h-10 items-center gap-2 rounded-full border py-1 pl-1 pr-2.5 text-sm font-medium"
                >
                    <span className="bg-primary text-primary-foreground flex size-8 shrink-0 items-center justify-center rounded-full text-xs font-semibold">
                        {nama === '' ? <UserRound className="size-4" /> : inisial(nama)}
                    </span>

                    <span className="max-w-[9rem] truncate md:max-w-[12rem]">
                        {nama === '' ? 'Akun saya' : nama}
                    </span>

                    <span aria-hidden="true" className="bg-border h-5 w-px" />

                    <Settings className="text-muted-foreground size-4 shrink-0" />
                    <ChevronDown className="text-muted-foreground size-4 shrink-0" />
                </button>
            </DropdownMenuTrigger>

            <DropdownMenuContent align="end" className="w-60 p-1.5">
                <DropdownMenuLabel className="text-muted-foreground truncate px-2 py-1.5 text-xs font-medium">
                    {nama === '' ? 'Akun Sehatly' : nama}
                </DropdownMenuLabel>

                <DropdownMenuItem asChild>
                    <Link to="/dashboard" className="gap-3 px-2 py-2.5">
                        <span className="bg-secondary flex size-8 shrink-0 items-center justify-center rounded-md">
                            <LayoutDashboard className="text-primary size-4" />
                        </span>

                        <span className="truncate text-sm font-medium">Dasbor</span>
                    </Link>
                </DropdownMenuItem>

                {isPasien ? (
                    <DropdownMenuItem asChild>
                        <Link to="/profil" className="gap-3 px-2 py-2.5">
                            <span className="bg-secondary flex size-8 shrink-0 items-center justify-center rounded-md">
                                <UserRound className="text-primary size-4" />
                            </span>

                            <span className="truncate text-sm font-medium">Profil</span>
                        </Link>
                    </DropdownMenuItem>
                ) : null}

                <DropdownMenuItem
                    data-slot="landing-akun-keluar"
                    disabled={signOut.isPending}
                    onSelect={() => signOut.mutate()}
                    className="gap-3 px-2 py-2.5"
                >
                    <span className="bg-secondary flex size-8 shrink-0 items-center justify-center rounded-md">
                        <LogOut className="text-primary size-4" />
                    </span>

                    <span className="truncate text-sm font-medium">Keluar</span>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/**
 * `onMasuk` belongs to the page, not to the header.
 *
 * The landing body has its own "Masuk" action, and two `LoginDialog` instances would each
 * hold their own challenge and race over the one `sessionStorage` key that carries it -
 * so the page owns the dialog and hands the opener down. The header renders it nowhere.
 */
export function LandingHeader({ onMasuk }: { onMasuk: () => void }) {
    const [open, setOpen] = useState(false);

    return (
        <header
            data-slot="landing-header"
            className="bg-card/95 border-b supports-[backdrop-filter]:bg-card/80 sticky top-0 z-40 w-full backdrop-blur"
        >
            <div className="relative mx-auto flex h-16 max-w-[1280px] items-center gap-3 px-4 md:h-[72px] md:gap-6 md:px-6">
                <Link
                    to="/"
                    className="flex shrink-0 items-center gap-2"
                    aria-label="Sehatly - beranda"
                >
                    <img
                        src="/logo.svg"
                        alt=""
                        aria-hidden="true"
                        className="size-9 shrink-0"
                    />

                    <span className="text-foreground text-xl font-bold tracking-tight md:text-[1.35rem]">
                        Sehatly
                    </span>
                </Link>

                <NavList className="hidden lg:flex" interaksi="hover" />

                {/*
                    `ml-auto` lives on the toggle rather than on a wrapper around the
                    whole right-hand cluster: it pushes the toggle, the action buttons
                    and the menu button to the right without re-indenting the sheet
                    below, and it keeps the nav glued to the logo when it appears.
                */}
                <ThemeToggle className="ml-auto" />

                <ActionButtons onMasuk={onMasuk} />

                <Sheet open={open} onOpenChange={setOpen}>
                    <SheetTrigger asChild>
                        <Button
                            variant="outline"
                            size="icon"
                            className="lg:hidden"
                            aria-label="Buka menu navigasi"
                        >
                            <Menu className="size-5" />
                        </Button>
                    </SheetTrigger>

                    <SheetContent side="right" className="w-[300px] sm:w-[340px]">
                        <SheetHeader>
                            <SheetTitle className="flex items-center gap-2">
                                <img
                                    src="/logo.svg"
                                    alt=""
                                    aria-hidden="true"
                                    className="size-7"
                                />

                                <span className="text-lg font-bold tracking-tight">
                                    Sehatly
                                </span>
                            </SheetTitle>
                        </SheetHeader>

                        <NavList
                            className="flex-col items-stretch gap-1 px-4"
                            interaksi="sentuh"
                            onNavigate={() => {
                                setOpen(false);
                            }}
                        />

                        <ActionButtons
                            className="mt-auto px-4 pb-4"
                            onMasuk={() => {
                                // The sheet closes first: two overlays stacked on one
                                // Escape press is a fight the user always loses.
                                setOpen(false);
                                onMasuk();
                            }}
                        />
                        {/*
                            No custom close button here: `SheetContent` already renders
                            `SheetPrimitive.Close` (with a screen-reader "Tutup" label) at
                            `absolute top-4 right-4`. Adding a second one only stacks two
                            X icons on the same coordinates - which is exactly what the
                            first draft of this component did.
                        */}
                    </SheetContent>
                </Sheet>
            </div>
        </header>
    );
}
