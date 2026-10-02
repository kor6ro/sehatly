import { BadgeCheck } from 'lucide-react';
import { Badge } from '@/components/ui/badge';

/**
 * The `Terverifikasi` badge, in the one form F04 §2/§8 permits.
 *
 * ## Text + icon, never colour alone
 *
 * `web/AGENTS.md` requires a status to be shown as text + icon + colour, and F04 §8 adds
 * that the verified badge must not be "just green". The label is the primary signal; the
 * `BadgeCheck` glyph is what makes it recognisable at a glance, and the success token
 * colours only the **icon** - the text stays `--foreground`, so the 4.5:1 text-contrast
 * floor does not depend on a status hue.
 *
 * ## It renders for exactly one status value
 *
 * `DokterController::show()` answers 404 for every status except `terverifikasi`, so a
 * profile that reaches this component is verified by construction. The explicit check is
 * still here: if a future resource publishes `pending`, the badge disappears rather than
 * claiming a verification that is not true - the same honesty rule that governs the rest
 * of this page. Copy is deliberately only "Terverifikasi" plus the detail in the
 * credentials block; F04 §3 forbids a badge without a stated criterion and forbids
 * "Dokter ini pasti aman".
 */
export function DoctorTrustBadge({ status }: { status: string }) {
    if (status !== 'terverifikasi') {
        return null;
    }

    return (
        <Badge variant="outline" className="border-success/60" data-slot="trust-badge">
            <BadgeCheck aria-hidden className="text-success" />

            Terverifikasi
        </Badge>
    );
}
