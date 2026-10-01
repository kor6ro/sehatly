import { useEffect } from 'react';

/**
 * Set `document.title` while a screen is mounted, and restore the previous one on exit.
 *
 * `web/AGENTS.md` forbids sensitive data in a tab title, and the F02 screen has a fixed
 * one - "Privasi dan data", never a consent status or a version. The restore matters
 * because the SPA has no server-rendered title per route: without it, navigating away
 * from a screen that set a title leaves that title on the next screen.
 */
export function useDocumentTitle(title: string): void {
    useEffect(() => {
        const sebelumnya = document.title;

        document.title = title;

        return () => {
            document.title = sebelumnya;
        };
    }, [title]);
}
