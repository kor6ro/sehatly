import {
    useCallback,
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
    ShoppingBag,
    Tag,
    UserRound,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { logout } from '@/lib/api/auth';
import { cariMasterPopuler, POPULER, spesialisasiOptions } from '@/lib/api/dokter';
import { serahkanKueri } from '@/features/dokter/direktori';
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
 * The layout is the catalog-menu reference's, down to its two rows: mark and wordmark on
 * the left of row ONE with a wide search pill between them and the account cluster on the
 * right, then row TWO carrying the tabs, from which both panels hang at the bar's own
 * width. Four deliberate differences from that reference:
 *
 * - No endorsement badge. The reference shows a ministry-of-health endorsement. Claiming
 *   one here would be a statement of fact about Sehatly that nobody has made, so the slot
 *   simply does not exist in this component.
 * - No "Daftar", and no wishlist heart. The reference sets "Masuk / Daftar" as text with
 *   a heart and a bag beside it; the product has ONE door (a phone number into the OTP
 *   dialog) and no wishlist route to point an icon at, so the second word and the heart
 *   would both be promises this product does not keep. The bag survives because
 *   `/pesanan` exists - it is where a prescription order already lives.
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
};

/**
 * The five service entry points, each resolving to a real route.
 *
 * They are listed twice on purpose - by the SHEET (touch) as rows with a `description`,
 * and by the desktop bar as the rail of its "Layanan Kesehatan" panel with an icon - but
 * from this one array, so the two surfaces cannot drift into offering different menus.
 * `description` is what the sheet reads and the bar does not: with no columns to fill on
 * a phone, the row explains itself instead.
 *
 * "Direktori Dokter" is deliberately NOT here. The bar gives it its own entry two items
 * to the left, so listing it would print one destination twice inside a single nav - a
 * menu pointing at itself. The landing page can afford to show it as a sixth "Solusi"
 * tile; a bar with three doors cannot.
 */
const LAYANAN: NavItem[] = [
    {
        to: '/konsultasi',
        label: 'Chat dengan Dokter',
        description: 'Konsultasi teks & video',
        icon: MessagesSquare,
    },
    {
        to: '/booking',
        label: 'Booking Janji Temu',
        description: 'Pilih jadwal dokter',
        icon: CalendarDays,
    },
    {
        to: '/pasien/resep',
        label: 'Resep & Apotek',
        description: 'Antar obat ke rumah',
        icon: Pill,
    },
    {
        to: '/pengingat',
        label: 'Pengingat Obat',
        description: 'Alarm minum obat',
        icon: Bell,
    },
    {
        to: '/rekam-medis',
        label: 'Rekam Medis',
        description: 'Riwayat kesehatan Anda',
        icon: FileText,
    },
];

/**
 * Where "the doctor directory" is: `/dokter`, a page of its own.
 *
 * Every link in the app that means "show me the doctors" points here - "Lihat semua
 * dokter" in the panel, the drawer's entry on a phone, the "Solusi" tiles, the footer -
 * and so does an empty search in the bar. A pick is different: it is answered on the page
 * the visitor is already reading, by `DirektoriSection`, so `pilihanSpesialisasi` below
 * keeps pointing at the landing page.
 */
const DIREKTORI = '/dokter';

/**
 * Where a specialisation row and a "Sering dicari" shortcut point: the LANDING PAGE,
 * carrying the choice as `?spesialisasi=`.
 *
 * The choice answers itself where it was made: `DirektoriSection` prints the
 * specialisation's name, how many doctors it has, up to eight cards and a door to the
 * rest. The door is `Buka direktori lengkap`, which continues into
 * `/dokter?spesialisasi=…` with the choice already applied - so the pick never has to
 * choose between "answer me here" and "take me to the catalogue"; it gets both, in that
 * order. It stays a plain `<a href>` rather than a click handler: shareable, Back-able,
 * keyboard-reachable, and honest about where it goes.
 */
const pilihanSpesialisasi = (kode: string) =>
    `/?spesialisasi=${encodeURIComponent(kode)}`;

function navLinkClass({ isActive }: { isActive: boolean }): string {
    return cn(
        'inline-flex items-center rounded-lg px-3 py-2 text-[15px] font-medium transition-colors',
        isActive
            ? 'bg-secondary text-primary'
            : 'text-foreground/75 hover:bg-secondary hover:text-primary',
    );
}

/**
 * The style of row TWO of the header - the reference's menu bar rather than three pill
 * buttons: small, uppercase, and carrying the underline that sits on the bar's own
 * bottom edge.
 *
 * Row two is 36px, which is measured rather than chosen: in the reference the open
 * plate runs y=71 down through its 4px rule at y=102..105, one lip of white at y=106,
 * and the panel at y=107. `pt-2.5` + a 16px line + `pb-[5px]` + that 4px rule + a 1px
 * lip reproduces those rows exactly - the last two living on the inner span, see
 * {@link navTabRuleClass} - and with row one at 71 the header ends on 107, which is
 * where the reference's panel begins. 44px, which is what the natural `pt-3.5` +
 * `pb-3` gives, makes the header a stack taller than the one it is drawn from and
 * pushes that edge down.
 *
 * ## Why `isActive` is the panel's `open`, not the route
 *
 * The reference underlines the category whose panel is showing, and on this bar that is
 * exactly "which tab is open". React Router cannot answer it: two of the three entries
 * are not routes at all (one opens a panel, one is an in-page anchor), so route matching
 * would leave the two that need the mark unmarked and keep the third lit for a hash no
 * path ever changes.
 *
 * ## Why the open tab grows a PLATE
 *
 * While a panel is open the bar is behind the veil (see {@link LandingHeader}), and a
 * tab that is merely underlined would be a dark word floating in a grey field with a
 * white panel hanging below it - two objects that look unrelated. The reference cuts
 * the open tab out of the dim instead: a solid `bg-popover` rectangle, rounded 16px at
 * the TOP only - measured on the plate's first row, y=71, where its white begins 16px
 * inside its left edge, which the `rounded-t-md` corner this started with would not do.
 * Tab, rule and panel are then one white shape with a line drawn across it, which is
 * what makes the menu read as a single object rather than a label and a box.
 *
 * The rule itself lives on an inner span ({@link navTabRuleClass}) rather than as this
 * button's `border-b`, because in the reference the rule is exactly as wide as its
 * WORDS: "Wanita" sets 309..364, its rule is drawn 309..364, and the plate around them
 * runs 293..380. A border on the button would draw the line from plate edge to plate
 * edge instead - the words underlined with sixteen more pixels of ink on each side,
 * which reads as a bar rather than as an underline.
 *
 * ## Why the plate carries a SKIRT
 *
 * The panel's top-left corner is rounded, like every other corner it has - the
 * reference rounds it too, which is what shows when a tab away from the edge owns the
 * menu ("Pria" in the visitor's picture: the panel's left edge curves back from the
 * bar). The trouble is the tab that sits ON that edge. Its plate ends where the bar
 * ends, the panel's corner then steps sixteen pixels inboard of the plate's straight
 * left edge, and the two objects that are supposed to read as one white shape are
 * joined by a bite. The reference has no bite: with "Wanita" open, the rows below the
 * panel's top at x=293..312 are unbroken white all the way down.
 *
 * So the sixteen pixels belong to the open TAB, not to the panel: a skirt of the same
 * `bg-popover` hangs from the plate over the corner while that panel is open, and
 * disappears with it. The first tab thereby welds its own junction smooth, and a panel
 * opened by a later tab keeps the curve everyone can see - which is both pictures.
 *
 * `bg-popover` and not `bg-card` because the panel it has to match uses `bg-popover`;
 * they are the same colour in both themes today, and a tab that matched card while the
 * panel matched popover would split the moment they stopped being.
 *
 * ## Why the sheet keeps {@link navLinkClass}
 *
 * The same list renders vertically inside the mobile sheet, where a border under every
 * row reads as a divider instead of as a selection, and where nothing can hover to earn
 * the mark. `NavList` picks between the two classes on `interaksi`.
 */
function navTabClass({ isActive }: { isActive: boolean }): string {
    return cn(
        'relative inline-flex items-center px-4 pt-2.5 pb-px text-xs font-semibold tracking-wide uppercase transition-colors',
        isActive ? 'rounded-t-2xl bg-popover text-foreground' : 'text-foreground/65 hover:text-foreground',
    );
}

/**
 * The black rule under a tab's words. Two measurements decide it: the reference's rule
 * is FOUR solid rows (y=102..105) with the line box ending at y=97, so `pb-[5px]` is
 * the gap that keeps the ink off the letters; and it is exactly as wide as the words -
 * drawn 309..364 under words set 309..364 - which is why it lives on this span rather
 * than as the button's own border, where the plate's 16px of padding would be inked
 * too. The button carries `pb-px` under all of this, the reference's single lip of
 * white between the rule and the panel it belongs to.
 */
function navTabRuleClass({ isActive }: { isActive: boolean }): string {
    return cn('border-b-4 pb-[5px]', isActive ? 'border-foreground' : 'border-transparent');
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
    onStatusChange,
}: {
    label: string;
    /** The `aria-controls` target, which only exists while the panel is open. */
    idPanel: string;
    panel: (hover: NavHover) => ReactNode;
    /**
     * Reports open/closed up to the bar, which paints ONE veil for whichever panel is
     * open - see {@link LandingHeader}.
     *
     * It reports per id rather than as a plain boolean on purpose: the two entries are
     * separate `useNavHover` instances, so crossing from one tab to the next leaves the
     * first open for its 160 ms grace period. A boolean would be last-writer-wins, and
     * the veil would switch OFF while the second panel was still up - a 160 ms flash of
     * undimmed page exactly at the moment the visitor is reading the menu.
     */
    onStatusChange?: (idPanel: string, open: boolean) => void;
}) {
    const hover = useNavHover();
    const trigger = useRef<HTMLButtonElement>(null);

    // After paint rather than during render: this reports INTO the bar, and a render-
    // phase update of a parent is the one React does not allow.
    useEffect(() => {
        onStatusChange?.(idPanel, hover.open);
        return () => onStatusChange?.(idPanel, false);
    }, [hover.open, idPanel, onStatusChange]);

    return (
        <div
            /**
             * `z-20` while open, so the tab and its panel rise above the veil the bar
             * paints at `z-10`.
             *
             * A FLEX item takes a z-index without taking `position`, and that is the
             * whole point: adding `relative` here would make this wrapper the panel's
             * containing block, and the panel would size itself to the width of one
             * tab instead of to the width of the bar. The wrapper stays unpositioned,
             * its z-index still applies, and both facts are asserted elsewhere.
             */
            className={hover.open ? 'z-20' : undefined}
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
                /**
                 * No chevron, which is the one ornament the reference tabs do not have:
                 * a menu bar's affordance is that hovering it opens something, and the
                 * state a screen reader needs is `aria-expanded` above rather than an
                 * arrow that only a pointer could have used anyway. The underline is the
                 * affordance for everyone else - it is what marks the open tab, the way
                 * the reference marks "Wanita".
                 */
                className={navTabClass({ isActive: hover.open })}
                onClick={() => {
                    // A mouse is already inside, and hover has already opened it; for a
                    // finger or a keyboard this is the only way in, so it toggles.
                    if (hover.diDalam.current) return;
                    hover.setOpen((nilai) => !nilai);
                }}
            >
                <span data-slot="nav-tab-garis" className={navTabRuleClass({ isActive: hover.open })}>
                    {label}
                </span>
                {/*
                    The plate's SKIRT - sixteen pixels of the same white running past the
                    bar's bottom edge, which is the panel's own corner radius. It exists
                    only while this panel is open, and it belongs to the TAB rather than
                    to the panel: see the plate's docblock for why the reference needs it
                    at the junction and does without it one tab further right.
                */}
                {hover.open ? (
                    <span
                        data-slot="nav-tab-rok"
                        aria-hidden
                        className="bg-popover pointer-events-none absolute inset-x-0 top-full h-4"
                    />
                ) : null}
            </button>

            {hover.open ? panel(hover) : null}
        </div>
    );
}

/**
 * The picture tiles BOTH mega panels carry, and where their photographs come from.
 *
 * `GET /hero` is the product's only public source of real photography, and it is the
 * admin's: a slide is written in `/admin/hero` with its image and its alt text, so the
 * menu's pictures change without a deploy for the same reason the carousel's do. Both
 * panels ask for the same query key, so the header costs no extra request on the landing
 * page - the carousel has already fetched it.
 *
 * The gallery is allowed to be empty: there is no grey box and no caption without a
 * picture, only whatever the campaign copy below contributes - a menu that promises an
 * image it cannot show is worse than a menu that simply has no photographs today.
 */
type KartuPanel = {
    kunci: string;
    judul: string;
    tautan: string;
    to: string;
    foto: string | null;
    alt: string;
    gradien: string;
};

function useKartuPanel(): KartuPanel[] {
    const hero = useQuery(heroOptions());

    return useMemo<KartuPanel[]>(() => {
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
}

/**
 * The six tiles themselves, shared so the two panels can never tell a visitor two
 * different stories about the same campaign: a photograph with its alt text when the
 * admin has published one, a painted card from the product's own copy when it has not -
 * the same bargain the hero carousel makes. Every card is a link, so a picture is also
 * a way in, and `tutup` closes the panel behind the choice.
 *
 * ## Why the tiles are SQUARE and fixed at 24rem
 *
 * Because the reference's are: six photographs of the same size in a 3x2 block, about a
 * tenth of the panel's width each, with a gap you can see - and an UNSQUARE one: 10px
 * between columns, 20px between rows, both measured off the reference. Square in the
 * other sense too: the reference's tiles have NO corner radius - the photo's colour
 * starts on the very pixel of the corner (1054,225 is beige, not white) - so these
 * carry no `rounded` either, only `overflow-hidden` for the hover zoom. 30rem gave
 * 152px tiles instead, a block that weighed a quarter more than the one it is drawn
 * from. 24rem with those gaps gives 121px squares (9.5% of this 1280px panel) against
 * the reference's 119px (9.2% of its 1296px).
 *
 * The block is CENTERED in the right-hand zone rather than left-aligned to it, because
 * the reference spends a fourth column on its brands and these panels have only three.
 * The rail could not keep the reference's 224px while keeping these names on one line:
 * the longest ("Spesialis Telinga Hidung Tenggorokan") measures 251px of text against
 * 58px of bar, icon and padding, so 224 wrapped seven of the sixteen rows and broke the
 * rhythm - the rail is 320px and the shortcuts column 256px instead. Centered in the
 * remaining 608 the block starts at x=764 against the reference's 761 (measured from
 * each panel's own left edge) and ends with 132px of slack against its 157: the offset
 * and the slack both land within a thumbnail of the menu this one is drawn from,
 * without a fourth column of invented content to buy them.
 *
 * The `aspect-[4/3]` they replaced filled the whole right-hand zone and turned a picture
 * grid into a wall, and the fixed `w-96` is what keeps the block the reference's
 * PROPORTION rather than one that grows with the window - the panel is `max-w-[1280px]`,
 * so a fixed 24rem is a fixed share of it at every width the bar can be. It is a WIDTH
 * and not a `max-w` because centered intrinsic sizing would otherwise measure the tiles'
 * own contents - all of which are absolutely positioned - and collapse the block to
 * nothing. Below `lg` the grid falls back to two columns at full width, because the
 * panels only render at `lg` and up and this is the sheet-free path.
 */
function GridPromoPanel({
    kartu,
    tutup,
}: {
    kartu: KartuPanel[];
    tutup: () => void;
}) {
    return (
        <div
            data-slot="nav-promo-grid"
            className="grid min-w-0 grid-cols-2 gap-x-2.5 gap-y-5 lg:mt-10 lg:w-96 lg:max-w-full lg:justify-self-center lg:grid-cols-3"
        >
            {kartu.map((kartu_) =>
                kartu_.foto !== null ? (
                    <Link
                        key={kartu_.kunci}
                        to={kartu_.to}
                        onClick={tutup}
                        data-slot="nav-promo"
                        className="group relative block aspect-square overflow-hidden"
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
                        onClick={tutup}
                        className="group relative block aspect-square overflow-hidden"
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
    );
}

/**
 * "Layanan Kesehatan" in the SAME Zalora shape as the directory's panel: a flat rail of
 * the services on the left, the campaign links in the middle, and the picture grid on the
 * right.
 *
 * ## Why the rail is flat
 *
 * It used to be one row per GROUP - "Konsultasi & Janji", "Obat & Apotek", "Riwayat
 * Kesehatan" - with the five services printed again under those headings in the middle.
 * That was the reference's category rail applied to a menu that has no categories: five
 * services are a list, not a taxonomy, and splitting a list into buckets only to print
 * the buckets AND the list is how a menu starts explaining itself instead of offering
 * something. The rail now prints `items` - the SAME five the sheet lists - one row per
 * service, so the two surfaces offer one menu rather than two that drift.
 *
 * ## Why "Direktori Dokter" is not on that rail
 *
 * The landing's "Solusi Kesehatan di Tanganmu" section has six tiles; this rail has five
 * rows, and the one left out is the directory. The bar already gives it its own entry two
 * items to the left, and a destination printed twice inside a single nav is a menu
 * pointing at itself: a visitor who wants the catalogue should open the catalogue's panel,
 * not find it filed under services. The page can afford it as a sixth tile; a bar with
 * three doors cannot.
 *
 * ## Why the middle carries the campaigns
 *
 * The reference's middle column is a text list of the same brands its right column shows
 * as pictures - six names beside six tiles. Ours is that bargain with our own data:
 * `PROMO`'s four campaigns as links, and the same four painted as tiles to the right.
 * What the middle must not carry is the service list again; that is what was removed.
 *
 * ## Why the title row's "Lihat semua" is an anchor and not a route
 *
 * The directory's see-all IS a route (`/dokter`), which is an index in the real sense:
 * the whole table, with its search and its filters. The services have none - a `/layanan`
 * URL would be a registered destination that resolves to nothing - so this one jumps to
 * `#solusi`, the landing's own six-tile section, which is the index it is promising. The landing page carries the sixth tile the
 * rail deliberately leaves out, which makes the jump a truthful "all of them" rather than
 * one of the five wearing a different hat.
 *
 * Every destination is honest about what it is: a route registered in `app/router.tsx`
 * - a signed-out visitor who picks one still lands on `RequireAuth` and its `/login`, the
 * correct outcome reached through the router - or the `#solusi` anchor above, which is
 * this page. Every one of them closes the panel on the way out (`hover.tutup`), so the
 * menu never lies open behind the page it opened.
 */
function PanelLayanan({ items, hover }: { items: NavItem[]; hover: NavHover }) {
    const kartu = useKartuPanel();
    const [sorot, setSorot] = useState(0);

    return (
        <div
            id="nav-layanan-panel"
            data-slot="nav-layanan-panel"
            className="bg-popover text-popover-foreground absolute top-full right-0 left-0 z-50 rounded-t-2xl rounded-b-2xl px-5 pt-6 pb-4 shadow-xl"
        >
            {/*
                The reference's title row: large word, blue "see all", nothing else.
                This panel's see-all is an ANCHOR to the landing's own "Solusi Kesehatan
                di Tanganmu" section rather than a route - a services menu has no index
                page, and `#solusi` points at the six tiles that ARE the index without
                adding a destination to `app/router.tsx` that would have to resolve to
                something. The deck goes with the old copy.
            */}
            <div className="flex flex-wrap items-baseline gap-x-5 gap-y-1">
                <p className="text-foreground text-[1.5rem] leading-10 font-normal">
                    Layanan Kesehatan
                </p>

                <a
                    href="#solusi"
                    onClick={hover.tutup}
                    className="text-primary inline-flex items-center gap-1 text-sm font-medium hover:underline"
                >
                    Lihat semua
                    <ArrowRight className="size-4" />
                </a>
            </div>

            <div
                data-slot="nav-layanan-zona"
                className="mt-3 grid items-start gap-6 lg:grid-cols-[minmax(0,20rem)_minmax(0,16rem)_minmax(0,1fr)] lg:gap-7"
            >
                {/* Zone 1 - the rail: one row per service, line art on every row, the
                    same five the sheet lists below it, and no visible heading - the
                    reference's rail starts at its first row so the rows and the middle
                    heading share a baseline. Its height takes the same
                    `min(35rem, 100vh - 16rem)` the directory's rail measures, so the
                    two panels never stand at different heights in one window - and it
                    is the same BLOCK the directory's rail paints (`bg-background`,
                    51px rows, square corners, the black bar resting on the first row
                    and following the pointer), because two rails that look different
                    would read as two different menus. */}
                <div className="min-w-0" onMouseLeave={() => setSorot(0)}>
                    <p className="sr-only">Layanan</p>

                    <RelMenggulir
                        slot="nav-layanan-daftar"
                        label="Daftar layanan"
                        className="bg-background max-h-[22rem] rounded lg:max-h-[min(35rem,calc(100vh_-_16rem))] lg:pr-3"
                    >
                        {items.map((layanan, i) => {
                            const Ikon = layanan.icon;

                            return (
                                <li key={layanan.to}>
                                    <Link
                                        to={layanan.to}
                                        onClick={hover.tutup}
                                        onFocus={() => setSorot(i)}
                                        onMouseEnter={() => setSorot(i)}
                                        className={cn(
                                            'flex items-center gap-3 border-l-4 py-4 pr-2 pl-3.5 transition-colors focus-visible:outline-none',
                                            i === sorot
                                                ? 'border-foreground bg-popover'
                                                : 'border-transparent bg-transparent',
                                        )}
                                    >
                                        <Ikon
                                            aria-hidden="true"
                                            className="text-foreground/80 size-4 shrink-0"
                                        />

                                        <span className="min-w-0 flex-1 text-sm leading-snug font-semibold">
                                            {layanan.label}
                                        </span>
                                    </Link>
                                </li>
                            );
                        })}
                    </RelMenggulir>
                </div>

                {/* Zone 2 - the campaigns as text, the same four the grid on the right
                    paints as pictures */}
                <div data-slot="nav-layanan-promo" className="grid min-w-0 gap-5">
                    <div className="min-w-0">
                        <p className="text-foreground flex items-center gap-1.5 text-sm font-semibold">
                            <Tag aria-hidden="true" className="size-4" />
                            Promo &amp; Penawaran
                        </p>

                        <ul className="mt-1.5 grid gap-1">
                            {PROMO.map((promo) => (
                                <li key={promo.judul}>
                                    <Link
                                        to={promo.to}
                                        onClick={hover.tutup}
                                        className="hover:bg-secondary hover:text-foreground block rounded-md px-2 py-1.5 text-muted-foreground transition-colors"
                                    >
                                        <span className="block text-sm leading-snug font-medium">
                                            {promo.judul}
                                        </span>

                                        <span className="mt-0.5 block text-xs leading-snug">
                                            {promo.deskripsi}
                                        </span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </div>
                </div>

                {/* Zone 3 - the picture grid, same six tiles as the directory panel */}
                <GridPromoPanel kartu={kartu} tutup={hover.tutup} />
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
    onStatusChange,
}: {
    label: string;
    items: NavItem[];
    /** Closes the mobile sheet, where the same list renders inline. */
    onNavigate?: () => void;
    interaksi: 'hover' | 'sentuh';
    /** The bar's veil hook, passed down to whichever entry can open a panel. */
    onStatusChange?: (idPanel: string, open: boolean) => void;
}) {
    if (interaksi === 'hover') {
        return (
            <NavPanelEntry
                label={label}
                idPanel="nav-layanan-panel"
                panel={(hover) => <PanelLayanan items={items} hover={hover} />}
                onStatusChange={onStatusChange}
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
 * under the title row - a scrolling icon rail of every specialisation, a column of
 * shortcuts, and a grid of pictures.
 *
 * ## Why it stopped being a link on the desktop
 *
 * As a `<NavLink>` the first pointer-down on this entry threw the visitor onto a full
 * directory - search, filters, result cards - before they knew what it held: the menu WAS
 * the destination instead of being the choice. The directory now lives as a section of
 * this very page, so a link here would no longer leave the landing page at all - but the
 * shape stands for its own reason: sixteen specialisations cannot be chosen from a single
 * word. Hovering reveals the choices first. The rules that keep it usable without a
 * pointer (focus, Escape, the sheet) are {@link NavPanelEntry}'s, not repeated here.
 *
 * ## Why the panel spans the bar instead of hugging the trigger
 *
 * It is a child of the entry's wrapper - so `pointerleave` does not fire while the
 * pointer moves into it - but the wrapper carries NO `position: relative`, which makes
 * row two of the header bar its containing block. That row's width is guaranteed (it is
 * `max-w-[1280px]` and centered, the same box row one is); a width measured from the
 * trigger has to guess how much room is left and guesses wrong on a narrow window.
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
 *   visible rows are not followed by a visible scrollbar reads as a finished list. The
 *   thumb rides the rail's own right edge - there is no divider line to ride: the
 *   reference has none, and the padding that keeps the rows clear of the thumb belongs
 *   to the `<ul>` rather than to the zone, so the wrapper the thumb is measured against
 *   is the same box the grey block paints. A bar floating a centimetre to the left of
 *   the edge it is supposed to be riding looks like a second, broken scrollbar.
 * - **The middle is one column of shortcuts**: "Sering dicari" resolves `POPULER`
 *   against the very table the rail just read, so a shortcut whose code no longer exists
 *   is skipped rather than offered against a filter that returns nothing. The services
 *   that used to sit beside it moved to the Layanan panel, where they are a menu of
 *   their own: a directory that pads its columns with appointment links is a directory
 *   that has lost track of what it is a directory of.
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
    const daftar = spesialisasi.data?.data.spesialisasi ?? [];
    const kartu = useKartuPanel();
    const [sorot, setSorot] = useState(0);

    return (
        <div
            id="nav-direktori-panel"
            data-slot="nav-direktori-panel"
            className="bg-popover text-popover-foreground absolute top-full right-0 left-0 z-50 rounded-t-2xl rounded-b-2xl px-5 pt-6 pb-4 shadow-xl"
        >
            {/*
                The reference's title row: one large word with a blue "see all" on its
                baseline, and nothing else. The deck this used to carry is gone with it -
                a menu that explains itself under its own title is copy written for a
                screenshot rather than for a visitor who is already looking at sixteen
                choices.
            */}
            <div className="flex flex-wrap items-baseline gap-x-5 gap-y-1">
                <p className="text-foreground text-[1.5rem] leading-10 font-normal">
                    Direktori Dokter
                </p>

                <Link
                    to={DIREKTORI}
                    onClick={() => {
                        hover.tutup();
                        // "Everything" has to be able to say so: a marker left behind by
                        // a search that never completed would otherwise arrive with the
                        // table already narrowed by a question this link promised to
                        // drop. See `serahkanKueri`.
                        serahkanKueri('');
                    }}
                    className="text-primary inline-flex items-center gap-1 text-sm font-medium hover:underline"
                >
                    Lihat semua dokter
                    <ArrowRight className="size-4" />
                </Link>
            </div>

            <div
                data-slot="nav-direktori-zona"
                className="mt-3 grid items-start gap-6 lg:grid-cols-[minmax(0,20rem)_minmax(0,16rem)_minmax(0,1fr)] lg:gap-7"
            >
                {/* Zone 1 - the rail: the whole table, one row per specialisation, and
                    no visible heading above it - the reference's rail starts at its
                    first row so that the rows and the middle column's heading share one
                    baseline. The column keeps its name for assistive technology only,
                    because a scroll region holding sixteen names still has to say which
                    list it is.

                    The rail is a BLOCK, not a white column with a rule beside it. The
                    reference paints its list `#F9F9F9` against a white panel - 224px
                    wide, rows 51px apart, the icon 18px in and the text at 46 - and
                    turns its FIRST row white behind a 4px black bar while the pointer
                    is somewhere else entirely. So the `<ul>` carries the block
                    (`bg-background`, 248 against 255 here, the reference's own 249
                    against 255) plus the clearance for the thumb (`lg:pr-3`), and each
                    row gives the longest name its last two pixels (`pr-2`: at `pr-3`,
                    "Spesialis Telinga Hidung Tenggorokan" has 250px of room and needs
                    251, so it wraps and breaks the rhythm the block exists to hold -
                    which is also why this block is 320px and not the reference's 224).
                    The rows are SQUARE for the same reason the tiles are: the
                    reference's white row is a sharp rectangle, white in all four
                    corners, and a rounded one would notch the block with grey.

                    The bar is PAINTED AT REST rather than earned by hover: `sorot`
                    starts on row 0, follows the pointer and the keyboard focus row by
                    row, and falls back to the first row when the pointer leaves the
                    rail - which is the state the reference is screenshotted in. It
                    stays honest by claiming nothing: no row is "current" on the
                    landing, so the bar marks where the eye is, never where the URL is.
                    The ZONE carries no border for the same reason the block needs no
                    divider - the reference draws none, and the block's own right edge
                    is the line the thumb rides.

                    The rail's own height is `min(35rem, 100vh - 16rem)`: the reference's
                    rail stands 550px inside a panel 649px tall, and 35rem gives this
                    panel 652px in the same proportion - a fixed 28rem stopped at 448px
                    whatever the screen, leaving this panel 110px shorter than the menu
                    it is drawn from. The viewport term is the ceiling for short windows:
                    at 720px it yields 464px, which puts the panel's bottom edge at 664
                    and keeps the strip of page under it that the veil tests sample. */}
                <div className="min-w-0" onMouseLeave={() => setSorot(0)}>
                    <p className="sr-only">Spesialisasi</p>

                    {spesialisasi.isPending ? (
                        <div className="grid gap-1 lg:pr-3">
                            {Array.from({ length: 8 }, (_, i) => (
                                <Skeleton key={i} className="h-12 rounded-lg" />
                            ))}
                        </div>
                    ) : null}

                    {spesialisasi.isError ? (
                        <p className="text-muted-foreground text-sm lg:pr-3">
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
                            className="bg-background max-h-[22rem] rounded lg:max-h-[min(35rem,calc(100vh_-_16rem))] lg:pr-3"
                        >
                            {daftar.map((baris, i) => {
                                const Ikon =
                                    IKON_SPESIALISASI[baris.kode] ?? IKON_BAWAAN;

                                return (
                                    <li key={baris.kode}>
                                        <Link
                                            to={pilihanSpesialisasi(baris.kode)}
                                            onClick={hover.tutup}
                                            onFocus={() => setSorot(i)}
                                            onMouseEnter={() => setSorot(i)}
                                            className={cn(
                                                'flex items-center gap-3 border-l-4 py-4 pr-2 pl-3.5 transition-colors focus-visible:outline-none',
                                                i === sorot
                                                    ? 'border-foreground bg-popover'
                                                    : 'border-transparent bg-transparent',
                                            )}
                                        >
                                            <Ikon
                                                aria-hidden="true"
                                                className="text-foreground/80 size-4 shrink-0"
                                            />

                                            <span className="min-w-0 flex-1 text-sm leading-snug font-semibold">
                                                {baris.nama}
                                            </span>
                                        </Link>
                                    </li>
                                );
                            })}
                        </RelMenggulir>
                    ) : null}
                </div>

                {/* Zone 2 - the shortcuts, the same data the body of the landing page
                    publishes. The service links moved to the Layanan panel: a visitor
                    hovering the DIRECTORY is choosing a doctor, and a column of
                    appointment links beside them answers a question nobody asked. */}
                <div data-slot="nav-direktori-pintasan" className="grid min-w-0 gap-5">
                    <div className="min-w-0">
                        <p className="text-foreground flex items-center gap-1.5 text-sm font-semibold">
                            <Search aria-hidden="true" className="size-4" />
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
                                            to={pilihanSpesialisasi(master.kode)}
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
                </div>

                {/* Zone 3 - the picture grid, which is where the panel's photographs
                    live; no heading, because the reference menu has none over its tiles */}
                <GridPromoPanel kartu={kartu} tutup={hover.tutup} />
            </div>
        </div>
    );
}

/** The entry itself: a plain link in the sheet, a {@link NavPanelEntry} on the bar. */
function NavDirektori({
    interaksi,
    onNavigate,
    onStatusChange,
}: {
    interaksi: 'hover' | 'sentuh';
    onNavigate?: () => void;
    onStatusChange?: (idPanel: string, open: boolean) => void;
}) {
    if (interaksi === 'sentuh') {
        return (
            <NavLink
                to={DIREKTORI}
                className={navLinkClass}
                onClick={() => {
                    onNavigate?.();
                    // Same promise as the panel's "Lihat semua dokter", one level down:
                    // the drawer's entry means the whole directory, so it clears any
                    // query a search in this same drawer left behind.
                    serahkanKueri('');
                }}
            >
                Direktori Dokter
            </NavLink>
        );
    }

    return (
        <NavPanelEntry
            label="Direktori Dokter"
            idPanel="nav-direktori-panel"
            panel={(hover) => <PanelDirektori hover={hover} />}
            onStatusChange={onStatusChange}
        />
    );
}

/**
 * The full nav, rendered as the bar's SECOND row on desktop and inside the sheet on
 * mobile.
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
 * The same list renders twice - in the bar's tab row (`hidden lg:block`) and inside the
 * mobile `Sheet` - and only one of those has a pointer that can hover. `hover` gives the
 * bar the panels that open themselves; `sentuh` keeps the sheet on links and click-to-
 * open menus, so a finger never opens something it cannot put away.
 */
function NavList({
    className,
    onNavigate,
    interaksi,
    onStatusChange,
}: {
    className?: string;
    onNavigate?: () => void;
    interaksi: 'hover' | 'sentuh';
    /** Forwarded only to entries that can open a panel, i.e. the bar's, never the sheet's. */
    onStatusChange?: (idPanel: string, open: boolean) => void;
}) {
    return (
        <nav
            aria-label="Navigasi utama"
            className={cn('flex items-center gap-1', className)}
        >
            <NavDirektori
                interaksi={interaksi}
                onNavigate={onNavigate}
                onStatusChange={onStatusChange}
            />

            <NavLayanan
                label="Layanan Kesehatan"
                items={LAYANAN}
                onNavigate={onNavigate}
                interaksi={interaksi}
                onStatusChange={onStatusChange}
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
             * A plain `<a>`, not a `NavLink`: `navLinkClass({ isActive: false })` on the
             * sheet and `navTabClass({ isActive: false })` on the bar give it the resting
             * style the other two entries use, and an active style would stay lit for a
             * link whose hash changes no path for React Router to match on.
             */}
            <a
                href="#cek-mandiri"
                onClick={onNavigate}
                className={
                    interaksi === 'hover'
                        ? navTabClass({ isActive: false })
                        : navLinkClass({ isActive: false })
                }
            >
                {/*
                    The same inner span the two triggers use, so the anchor measures the
                    36px they measure and the three entries sit on one baseline. On the
                    sheet it carries no class at all: a rule there would read as a
                    divider, which is the whole reason the sheet keeps `navLinkClass`.
                */}
                <span className={interaksi === 'hover' ? navTabRuleClass({ isActive: false }) : undefined}>
                    Cek Kesehatan Mandiri
                </span>
            </a>
        </nav>
    );
}

/**
 * The search field in the middle of the bar's FIRST row.
 *
 * ## Where a search goes, and why the question is not in the address
 *
 * The directory is the only part of the product that ANSWERS a query - it carries a
 * `search` box of its own, and `GET /dokter` takes `search` as a parameter - so a header
 * field searching anything else would be a field that pretends. The form opens `/dokter`,
 * the page that answers.
 *
 * What it must not do is carry the question in the URL. F03 §9 forbids a free-text doctor
 * query in a shareable address, because "kanker" is a condition and the address is the
 * thing people paste into chats. So the query travels in `sessionStorage` instead - one
 * write by {@link serahkanKueri}, one read by `DirektoriDokter`'s initializer - and the
 * address the visitor lands on says `/dokter` and nothing else. This app never WRITES
 * `?search=`; the older links that still carry it are read, not made.
 *
 * An empty query writes an EMPTY marker rather than no marker at all. "I searched for
 * nothing" and "I never searched" have to be different answers, and only the first may
 * clear a query the directory is already carrying.
 *
 * ## Why it is a form and not a per-keystroke request
 *
 * A field that queries on every character has to decide what happens when the visitor
 * walks away mid-word, and every answer is worse than the one a submit button gives.
 * The dark circle at the end is the reference's, and it is a real submit button - Enter
 * in the field does the same thing it does.
 *
 * `onCari` is the mobile sheet's hook: the form also renders inside the drawer, where
 * navigating without closing would leave the panel stacked over the page it just opened.
 */
function PencarianBar({
    className,
    onCari,
}: {
    className?: string;
    onCari?: () => void;
}) {
    const navigate = useNavigate();
    const [kueri, setKueri] = useState('');

    return (
        <form
            role="search"
            data-slot="landing-cari"
            onSubmit={(event) => {
                event.preventDefault();

                serahkanKueri(kueri.trim());
                navigate(DIREKTORI);
                onCari?.();
            }}
            className={cn(
                'border-input bg-background focus-within:border-foreground/40 flex h-10 min-w-0 max-w-[42rem] flex-1 items-center gap-1 rounded-full border pl-4 pr-1 transition-colors',
                className,
            )}
        >
            <input
                type="search"
                value={kueri}
                onChange={(event) => setKueri(event.target.value)}
                aria-label="Cari dokter"
                placeholder="Cari nama dokter (contoh: dr. Rina)"
                className="placeholder:text-muted-foreground min-w-0 flex-1 bg-transparent text-sm outline-none"
            />

            <button
                type="submit"
                aria-label="Cari"
                className="bg-foreground text-background hover:opacity-85 flex size-8 shrink-0 items-center justify-center rounded-full transition-opacity"
            >
                <Search aria-hidden="true" className="size-4" />
            </button>
        </form>
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
            {/*
                The reference's account control is TEXT with a person in front of it,
                not a filled pill: in a bar whose widest element is the search, the
                account is a destination rather than a call to action, and a solid blue
                button there shouts over everything else in the row. `variant="ghost"`
                takes the fill away while leaving it the `<button>` that the dialog, the
                keyboard path and every test in `landing.spec.ts` already expect - the
                role is the contract, the colour is only a costume.
            */}
            <Button
                variant="ghost"
                onClick={onMasuk}
                className="text-foreground/80 hover:text-foreground gap-1.5 rounded-lg px-2.5 font-medium"
            >
                <UserRound aria-hidden="true" className="size-4" />
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
 *
 * ## The veil: the page goes dark while a panel is open
 *
 * The reference opens its menu over a dimmed page, and the reason is geometry rather than
 * taste: a white panel dropped on a white page has no edge anywhere except the shadow,
 * and a shadow over a photograph reads as smudge. Dimming the page gives the panel an
 * edge on all four sides at once, and it costs one absolutely-positioned box.
 *
 * It takes TWO boxes, and they split the job between them. The header carries
 * `backdrop-blur`, and `backdrop-filter` - like `filter` - makes its box the containing
 * block for fixed descendants, so a `position: fixed` child stops at the bar's own edge
 * (measured, not assumed: 1440x116 inside a 1440x950 viewport). Both halves therefore
 * hang off the header itself:
 *
 * - `landing-tirai-bar` is `inset-0` at `z-10`. That is above the header's own
 *   background (layer 1), above row one, which is in-flow (layer 3), and above row two,
 *   which is positioned with `z: auto` (layer 6) - so the wordmark, the search, the
 *   account cluster and the whole tab row all take the veil, full header width.
 * - `landing-tirai-halaman` is `top-full h-screen` at `z-0`, and it starts exactly where
 *   the bar ends. The bar is `sticky top-0`, so one viewport height from its bottom edge
 *   IS the rest of the screen; and living inside a `z-40` header already puts it above
 *   every element the landing page renders, with no z-index negotiation against them.
 *
 * What stays bright is exactly what the reference keeps bright: the open tab and its
 * panel. {@link NavPanelEntry} gives that entry `z-20`, which lands above the veil's
 * `z-10` in the header's own stacking context.
 *
 * ## How dark: a quarter, not a wash
 *
 * The level is measured rather than picked. The reference's bar and the page under it
 * both read `189` where their undimmed white is `255` - 74% of the surface beneath,
 * which is a black box at 25% (`bg-black/25`). The 40% this used to carry dimmed the
 * page to 60%, and a side-by-side against the menu it is drawn from is where that
 * shows: everything except the panel loses more than a third of its brightness, and
 * the panel stops reading as a sheet of paper laid ON the page and starts reading as
 * a light source in a dark room. A quarter still gives the panel an edge on all four
 * sides - which is the entire reason the veil exists - at the reference's weight.
 *
 * Both boxes are `pointer-events-none`, which is the difference between a veil and a
 * modal. The veil changes what things LOOK like and nothing else: the panel still closes
 * on a pointer leaving, on Escape, on a link that navigates, and a click on the heading
 * behind an open menu still lands on that heading. An overlay that swallowed clicks
 * would be a behavior change wearing a costume - and it would fail the very tests that
 * pin the panel's links down.
 */
export function LandingHeader({ onMasuk }: { onMasuk: () => void }) {
    const [open, setOpen] = useState(false);

    /**
     * Which panels are open, as ids rather than one boolean.
     *
     * The two entries are separate {@link useNavHover} instances with their own 160 ms
     * grace period, so crossing from one tab to the next leaves BOTH open for that beat.
     * One boolean would be last-writer-wins and the veil would switch off mid-read; a
     * set only goes dark when the last panel has really closed.
     *
     * Reporting `false` for a panel that is already absent returns the SAME set, which
     * is what keeps the report effect from re-rendering the bar forever.
     */
    const [panelTerbuka, setPanelTerbuka] = useState<ReadonlySet<string>>(() => new Set());

    const laporkanPanel = useCallback((idPanel: string, buka: boolean) => {
        setPanelTerbuka((sebelumnya) => {
            if (sebelumnya.has(idPanel) === buka) return sebelumnya;

            const berikut = new Set(sebelumnya);
            if (buka) berikut.add(idPanel);
            else berikut.delete(idPanel);
            return berikut;
        });
    }, []);

    const adaPanel = panelTerbuka.size > 0;
    const tirai = (buka: boolean) =>
        cn(
            'bg-black/25 pointer-events-none transition-opacity duration-200',
            buka ? 'opacity-100' : 'opacity-0',
        );

    return (
        <header
            data-slot="landing-header"
            className="bg-card/95 border-b supports-[backdrop-filter]:bg-card/80 sticky top-0 z-40 w-full backdrop-blur"
        >
            <div className="mx-auto grid h-16 max-w-[1280px] grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-3 px-4 md:h-[71px] md:gap-5 md:px-6">
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

                {/*
                    The search owns row one's MIDDLE column and is centered inside it,
                    which is what puts it where the reference puts it: not flush after
                    the wordmark and not flush before the actions, but a fixed-width
                    pill whose centre sits a little left of the container's, because the
                    wordmark is narrower than the cluster on the right. `justify-self-
                    center` does that arithmetically instead of by leaving a gap that a
                    longer wordmark would eat. Hidden below `md`, where the row keeps the
                    wordmark and the menu button - a phone still has the directory's own
                    search field, so nothing here is the only way to search.
                */}
                <PencarianBar className="col-start-2 hidden w-full max-w-[42rem] justify-self-center md:flex" />

                {/*
                    The right-hand column, as one grid cell: theme, account, bag, menu.
                    Keeping them together is what lets the middle column stay exactly
                    `minmax(0,1fr)` - with `ml-auto` on the first of four siblings the
                    free space went wherever the tallest of them left it, and the search
                    drifted left every time the account pill grew a longer name.
                */}
                <div className="col-start-3 flex items-center gap-1.5">
                    <ThemeToggle />

                    <ActionButtons onMasuk={onMasuk} />

                    {/*
                        The bag the reference carries after the account, pointed at the one
                        route that answers it: `/pesanan` is where a prescription order
                        lives. It is offered signed-out on purpose, for the same reason every
                        service link is - `RequireAuth` sends the visitor to `/login`, which
                        is the correct outcome reached through the router rather than a link
                        that pretends a cart exists. The reference's heart is deliberately
                        NOT reproduced: there is no wishlist route to point it at, and an
                        icon whose destination is "nowhere" is a dead link wearing a
                        costume.
                    */}
                    <Link
                        to="/pesanan"
                        aria-label="Pesanan obat"
                        className="text-foreground/75 hover:bg-secondary hover:text-foreground inline-flex size-9 items-center justify-center rounded-lg transition-colors"
                    >
                        <ShoppingBag aria-hidden="true" className="size-5" />
                    </Link>

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

                            {/*
                                The drawer's own copy of the search: on a phone the top
                                bar keeps only the wordmark and the menu button, so
                                without this the field would be a desktop-only feature,
                                and a search a visitor has to know to look for is one
                                they will not find. `flex-none` matters here: this sheet
                                lays its children out in a COLUMN, and the bar's `flex-1`
                                would grow the field to fill the drawer instead of
                                leaving it at h-10.
                            */}
                            <PencarianBar
                                className="mx-4 flex-none"
                                onCari={() => setOpen(false)}
                            />

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
            </div>

            {/*
                Row two: the tabs, and the containing block both panels hang from.

                They are `left-0 right-0`, so they take the width of whatever is
                `relative` around them - and that must be THIS row (`max-w-[1280px]`,
                centered) rather than the sticky header above it, which is `w-full`: the
                panels would otherwise span the whole viewport and run off both edges of
                the window. The row keeps row one's exact horizontal geometry too, which
                is what makes "the panel is as wide as the bar" a testable claim rather
                than a coincidence.

                Its own padding is NONE horizontally, and that is the reference's geometry
                rather than a taste: there the tab plate (293..380), the rule under the
                tab row (293..1590) and the panel (293..1590) all begin on one pixel, so
                the open tab and the menu it owns are a single white shape descending
                from the same edge. Even twelve pixels of padding here would put the
                plate inboard of the panel and let the panel stick out past the
                navigation's own effect on the left - a step in the silhouette the
                reference does not have. Each tab keeps its own `px-4`, the reference's
                16px inset, so its words sit 16px inside the plate while the wordmark
                stays at row one's `px-6`: the tabs hang a little further left than the
                logo, as they do there (309 against 324).
            */}
            <div
                data-slot="landing-nav-baris"
                className="relative mx-auto hidden max-w-[1280px] lg:block"
            >
                <NavList interaksi="hover" onStatusChange={laporkanPanel} />
            </div>

            {/*
                The veils come LAST in the DOM, so that the bar's first `div` child is
                still row one - several assertions read that row by position. They are
                not placed by order anyway: `z-10` and `z-0` settle it against row one's
                in-flow boxes and row two's `z: auto`.

                The bar's half: `inset-0` against a sticky header is that header's own
                box, full width, both rows - the wordmark, the search, the account
                cluster and the tab row all take it.
            */}
            <div
                data-slot="landing-tirai-bar"
                aria-hidden="true"
                className={cn('absolute inset-0 z-10', tirai(adaPanel))}
            />

            {/*
                The page's half: `top-full` starts it exactly where the bar ends and
                `h-screen` carries it down past everything the landing renders. The bar is
                `sticky top-0`, so "one viewport height from the bar's bottom edge" IS the
                rest of the screen, and being a child of a `z-40` header already puts it
                above every page element - no z-index negotiation with the sections below.

                `z-0` rather than nothing, for one reason: it must sit UNDER the open
                entry's `z-20`, because the panel it is meant to sit behind hangs down
                into this very area.
            */}
            <div
                data-slot="landing-tirai-halaman"
                aria-hidden="true"
                className={cn('absolute inset-x-0 top-full z-0 h-screen', tirai(adaPanel))}
            />
        </header>
    );
}
