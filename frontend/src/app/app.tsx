import { QueryClientProvider } from '@tanstack/react-query';
import { RouterProvider } from 'react-router';
import { AppErrorBoundary } from '@/app/error-boundary';
import { router } from '@/app/router';
import { queryClient } from '@/lib/query-client';
export function App() {
    return (
        <AppErrorBoundary>
            <QueryClientProvider client={queryClient}>
                <RouterProvider router={router} />
            </QueryClientProvider>
        </AppErrorBoundary>
    );
}
