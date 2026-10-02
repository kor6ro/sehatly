import { useCallback, useRef } from 'react';
import { useMutation } from '@tanstack/react-query';
import { tandaiDibacaMutation } from '@/lib/api/notifikasi';
import { useOnlineStatus } from '@/hooks/use-online-status';

/**
 * Mark one notification read, with the two guards F11 asks for.
 *
 * ## Exactly one request on a double click
 *
 * `PUT /notifikasi/{id}/baca` is idempotent server-side - it keeps the first
 * `dibaca_at` and writes no second audit row - but "harmless on the data" is not
 * "harmless on the wire": a double click should not spend two of the endpoint's ten
 * per-minute throttle tokens. `disabled` on its own is a render-timing bet, because the
 * second click of a fast double click can be dispatched before React commits the
 * disabled attribute. The `sedangBerjalan` ref is the actual guard: `tandaiSatu` flips it
 * **synchronously**, before `mutate` is called, so a second click in the same tick
 * returns without issuing a request. The flag is cleared when the mutation settles,
 * which is also when the cache invalidation lands.
 *
 * ## Offline disables the write instead of queueing it
 *
 * `_global.md` §7 #1 is explicit that writes are not queued for replay. `tandaiSatu`
 * refuses while `navigator.onLine` is false, and the caller renders the reason beside
 * the disabled control. Navigation itself is client-side and still works offline; only
 * the `PUT` is withheld.
 */
export function useTandaiDibaca() {
    const online = useOnlineStatus();
    const sedangBerjalan = useRef(false);

    const tandai = useMutation(
        tandaiDibacaMutation(() => {
            sedangBerjalan.current = false;
        }),
    );

    /**
     * `sudahDibaca` skips the write for an already-read row: the server would answer
     * 200 and change nothing, but there is no reason to spend a request proving it.
     */
    const tandaiSatu = useCallback(
        (id: number, sudahDibaca: boolean): void => {
            if (!online || sudahDibaca || sedangBerjalan.current) {
                return;
            }

            sedangBerjalan.current = true;

            tandai.mutate(id);
        },
        [online, tandai],
    );

    return {
        online,
        tandaiSatu,
        /** A request is in flight; the control is disabled until it settles. */
        sedangMenandai: tandai.isPending,
        /** Offline and in-flight are both "cannot write right now". */
        bisaMenandai: online && !tandai.isPending,
    };
}
