import { useEffect } from 'react';
import { QueryClientProvider } from '@tanstack/react-query';
import { RouterProvider } from 'react-router';
import { AppErrorBoundary } from '@/app/error-boundary';
import { router } from '@/app/router';
import { queryClient } from '@/lib/query-client';
import { onSessionExpired } from '@/lib/http';
import { detectPortal } from '@/lib/portal';

function SessionGuard() {
    useEffect(() => {
        return onSessionExpired(() => {
            queryClient.clear();

            const portal = detectPortal();
            const tujuan = portal === 'pasien' ? '/?masuk=1' : '/login';

            void router.navigate(tujuan, { replace: true });
        });
    }, []);

    return null;
}

export function App() {
    return (
        <AppErrorBoundary>
            <QueryClientProvider client={queryClient}>
                <SessionGuard />

                <RouterProvider router={router} />
            </QueryClientProvider>
        </AppErrorBoundary>
    );
}
