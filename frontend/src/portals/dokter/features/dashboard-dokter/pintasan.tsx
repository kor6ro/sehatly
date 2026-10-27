import { useEffect, useRef, useState } from 'react';
import { Keyboard } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

/**
 * The `?` shortcut reference, and the global key listener that opens it.
 *
 * Material's rule is a list of every custom shortcut plus at least two buttons'
 * worth of discoverability, so the dialog lists only shortcuts that actually work
 * here. Radix owns the focus trap and restores focus to the trigger on close, which
 * is why `Esc` needs no handler of its own.
 *
 * The listener is deliberately blind while a form control has focus: a doctor
 * typing `?` into a medical textarea is typing a character, not summoning help.
 */
export function PintasanDialog() {
    const [terbuka, setTerbuka] = useState(false);
    const pemicu = useRef<HTMLButtonElement>(null);

    useEffect(() => {
        const padaTombol = (event: KeyboardEvent): void => {
            if (event.key !== '?' || event.defaultPrevented) {
                return;
            }

            const target = event.target;

            if (target instanceof HTMLElement && (sedangMengetik(target) || target.isContentEditable)) {
                return;
            }

            event.preventDefault();
            setTerbuka(true);
        };

        window.addEventListener('keydown', padaTombol);

        return () => {
            window.removeEventListener('keydown', padaTombol);
        };
    }, []);

    return (
        <>
            <Button
                ref={pemicu}
                type="button"
                variant="outline"
                size="icon"
                data-testid="f13-aksi"
                aria-label="Pintasan keyboard"
                title="Pintasan keyboard"
                className="size-11"
                onClick={() => {
                    setTerbuka(true);
                }}
            >
                <Keyboard aria-hidden />
            </Button>

            <Dialog open={terbuka} onOpenChange={setTerbuka}>
                <DialogContent
                    data-slot="f13-pintasan"
                    onCloseAutoFocus={(event) => {
                        event.preventDefault();

                        pemicu.current?.focus();
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>Pintasan</DialogTitle>

                        <DialogDescription>
                            Pintasan berlaku selama fokus berada di dasbor.
                        </DialogDescription>
                    </DialogHeader>

                    <dl className="flex flex-col gap-2 text-sm">
                        <PintasanBaris tombol="?" aksi="Buka daftar pintasan" />
                        <PintasanBaris tombol="Esc" aksi="Tutup dialog" />
                        <PintasanBaris tombol="↓ / ↑" aksi="Pindah baris antrean" />
                        <PintasanBaris tombol="Enter" aksi="Buka konsultasi pada baris terpilih" />
                        <PintasanBaris tombol="Tab" aksi="Pindah kontrol berikutnya" />
                    </dl>
                </DialogContent>
            </Dialog>
        </>
    );
}

function PintasanBaris({ tombol, aksi }: { tombol: string; aksi: string }) {
    return (
        <div className="flex items-center justify-between gap-4">
            <dt>
                <kbd className="bg-muted rounded-md border px-2 py-0.5 font-mono text-xs">
                    {tombol}
                </kbd>
            </dt>

            <dd className="text-muted-foreground text-right">{aksi}</dd>
        </div>
    );
}

function sedangMengetik(element: HTMLElement): boolean {
    const tag = element.tagName.toLowerCase();

    return tag === 'input' || tag === 'textarea' || tag === 'select';
}
