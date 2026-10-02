import { WifiOff } from 'lucide-react';
import { useOnlineStatus } from '@/hooks/use-online-status';
import { cn } from '@/lib/utils';

/**
 * The F15 thin slice's one shared offline surface.
 *
 * ## What it does, and what it refuses to do
 *
 * It detects and it tells the truth: while `navigator.onLine` is `false` the banner is
 * rendered with `role="status"`, and the form that mounts it disables its submit. It does
 * **not** queue the write. `_global.md` §7 #1 is explicit - a replayed `POST /booking` is a
 * second booking, not a duplicate read - so the patient keeps their input and sends it
 * themselves once the connection is back.
 *
 * ## Why `role="status"` and not `role="alert"`
 *
 * Losing a connection is a state, not an event that interrupts a task: the patient is
 * still typing, and an assertive announcement would cut across whatever a screen reader is
 * already reading. `role="status"` is polite by default, which is the right volume for
 * "this will matter when you press send".
 *
 * ## Why the copy names the action
 *
 * "Anda sedang luring. Periksa koneksi internet Anda." says what happened and what to do
 * about it, in the calm register `AGENTS.md` requires. It does not say "Koneksi gagal" -
 * that names a symptom and leaves the user with nothing to act on.
 */
export function OfflineBanner({
    className,
    message = 'Anda sedang luring. Periksa koneksi internet Anda.',
}: {
    className?: string;
    /**
     * Overrides the default sentence. F10's read-only history shows the cached-data
     * variant ("Menampilkan data terakhir yang tersimpan."); flows that gate a write
     * keep the default, which names the action.
     */
    message?: string;
}) {
    const online = useOnlineStatus();

    if (online) {
        return null;
    }

    return (
        <div
            role="status"
            data-testid="offline-banner"
            className={cn(
                'border-warning/40 bg-warning/10 flex items-start gap-2 rounded-lg border p-3 text-sm',
                className,
            )}
        >
            <WifiOff aria-hidden className="mt-0.5 size-4 shrink-0" />

            <p>{message}</p>
        </div>
    );
}
