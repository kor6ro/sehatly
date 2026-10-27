import { Moon, Sun } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useAppearance } from '@/hooks/use-appearance';

/**
 * The single control that moves the app between the light and dark palettes.
 *
 * ## Why it carries its own `TooltipProvider`
 *
 * Nothing in the app renders `TooltipProvider` at the root - `sidebar.tsx` opens a
 * `<Tooltip>` without one - so a tooltip added up here cannot rely on a provider
 * being mounted above it. Wrapping the provider locally keeps this button working
 * on its own instead of making a landing header depend on where `AppShell` mounts.
 *
 * ## Why the icon shows the current mode while the label names the action
 *
 * A screen-reader user never sees the icon, so `aria-label` states what pressing the
 * button *does* ("Ganti ke tema terang"). A sighted user does see the icon, and the
 * convention for this control is that it depicts the mode that is currently on - the
 * same way a light switch is labelled by the state it leaves behind.
 *
 * This is also the first caller of `useAppearance().updateAppearance`, which until
 * now had none: the light default is the product's, and this button is the only way
 * back to `.dark`, whose palette is still defined in `styles/app.css`.
 */
export function ThemeToggle({ className }: { className?: string }) {
    const { resolvedAppearance, updateAppearance } = useAppearance();
    const isDark = resolvedAppearance === 'dark';

    return (
        <TooltipProvider>
            <Tooltip>
                <TooltipTrigger asChild>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className={className}
                        aria-label={
                            isDark ? 'Ganti ke tema terang' : 'Ganti ke tema gelap'
                        }
                        onClick={() => {
                            updateAppearance(isDark ? 'light' : 'dark');
                        }}
                    >
                        {isDark ? (
                            <Moon className="size-5" />
                        ) : (
                            <Sun className="size-5" />
                        )}
                    </Button>
                </TooltipTrigger>

                <TooltipContent side="bottom" align="end">
                    {isDark ? 'Tema gelap' : 'Tema terang'}
                </TooltipContent>
            </Tooltip>
        </TooltipProvider>
    );
}
