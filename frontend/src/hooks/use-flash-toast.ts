import { useEffect } from 'react';
import { toast } from 'sonner';
import { FLASH_EVENT, type Flash } from '@/lib/flash';

/**
 * The relocated `sonner.tsx` needs a flash source. Under Inertia that was the
 * router's `flash` event, which does not exist in a Vite SPA, so this listens
 * for the `sehatly:flash` DOM event instead. Todo 20's auth endpoints and the
 * module todos raise it through `dispatchFlash()`.
 */
export function useFlashToast(): void {
    useEffect(() => {
        const handle = (event: Event): void => {
            const flash = (event as CustomEvent<Flash>).detail;

            if (flash === undefined) {
                return;
            }

            toast[flash.level](flash.message);
        };

        globalThis.addEventListener(FLASH_EVENT, handle);

        return () => globalThis.removeEventListener(FLASH_EVENT, handle);
    }, []);
}
