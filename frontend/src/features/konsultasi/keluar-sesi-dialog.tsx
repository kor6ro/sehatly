import { useState } from 'react';
import { useNavigate } from 'react-router';
import { LogOut } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';

/**
 * The patient's way out of a consultation room.
 *
 * ## Leaving is not ending
 *
 * `PUT /konsultasi/{id}/selesai` is the DOCTOR's transition and it is the only one
 * that closes a session; a patient pressing "Keluar dari sesi" must change no
 * status at all. It navigates away and says so: the session keeps running, the
 * transcript stays stored, and the room can be reopened. `_global.md` §4's dialog
 * pattern is followed - consequence stated in the body, both actions visible, no
 * `window.confirm` - but the confirm button is NOT `destructive`, because this
 * destroys nothing.
 *
 * The dialog is only rendered for a patient; a doctor closes the session with the
 * SOAP form instead.
 */
export function KeluarSesiDialog({
    konsultasiId,
    className,
}: {
    konsultasiId: number;
    className?: string;
}) {
    const navigate = useNavigate();
    const [terbuka, setTerbuka] = useState(false);

    return (
        <Dialog open={terbuka} onOpenChange={setTerbuka}>
            <DialogTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    className={className ?? 'min-h-11 w-fit'}
                >
                    <LogOut aria-hidden />

                    Keluar dari sesi
                </Button>
            </DialogTrigger>

            <DialogContent data-konsultasi={konsultasiId}>
                <DialogHeader>
                    <DialogTitle>Keluar dari ruang konsultasi?</DialogTitle>

                    <DialogDescription>
                        Anda akan kembali ke daftar konsultasi. Konsultasi ini tetap
                        berjalan sampai dokter mengakhirinya, dan seluruh riwayat pesan
                        tetap tersimpan. Anda dapat membuka kembali ruang ini kapan saja.
                    </DialogDescription>
                </DialogHeader>

                <DialogFooter>
                    <DialogClose asChild>
                        <Button type="button" variant="outline" className="min-h-11">
                            Tetap di ruang konsultasi
                        </Button>
                    </DialogClose>

                    <Button
                        type="button"
                        className="min-h-11"
                        onClick={() => {
                            setTerbuka(false);
                            void navigate('/konsultasi');
                        }}
                    >
                        Keluar dari sesi
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
