import {
    Activity,
    Baby,
    Bone,
    Brain,
    Droplets,
    Ear,
    Eye,
    Flower2,
    Hand,
    Heart,
    Scissors,
    Sparkles,
    Stethoscope,
    Wind,
    Zap,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

/**
 * The line art for a specialisation, keyed by `master_spesialisasi.kode`.
 *
 * ## Why a table and not an `if` chain
 *
 * The codes are the reference table's own (`SP.A`, `GIGI`, ...), so this map is the only
 * place that translates a code the API owns into a shape a visitor recognises. It is
 * deliberately a FALLBACK-ABLE table: an unknown or newly seeded code renders
 * {@link IKON_BAWAAN} rather than throwing or leaving an empty box, because the icon is
 * decoration and the label beside it is what actually names the specialty.
 *
 * The icons are `aria-hidden` for the same reason - a link whose accessible name is
 * "Spesialis Mata" should not also announce an eye.
 *
 * ## Why it lives in its own file
 *
 * It used to sit inside `sections.tsx`, next to the landing section that has since been
 * deleted: the catalog-panel shape moved into the header's {@link PanelDirektori}, and a
 * table of glyphs is not layout. Keeping it here means the header, the directory page and
 * anything else that lists the table can share one translation instead of re-spelling
 * `SP.BP` three times and drifting on the fourth.
 */
export const IKON_SPESIALISASI: Readonly<Record<string, LucideIcon>> = {
    UMUM: Stethoscope,
    // Gigi: sparkles, because lucide ships no tooth and a bone would say "orthopaedi".
    GIGI: Sparkles,
    'SP.A': Baby,
    'SP.B': Scissors,
    'SP.BP': Scissors, // both are surgery, which is how Zalora reuses one glyph too
    'SP.JP': Heart,
    'SP.KJ': Brain,
    'SP.KK': Hand,
    'SP.M': Eye,
    'SP.N': Zap, // the impulse, which is what a nerve conducts
    'SP.OG': Flower2,
    'SP.P': Wind,
    'SP.PD': Activity,
    'SP.S': Bone,
    'SP.THT': Ear,
    'SP.U': Droplets,
};

/** What an unmapped code gets: a doctor, which is never a wrong thing to draw. */
export const IKON_BAWAAN = Stethoscope;
