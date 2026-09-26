import { createBrowserRouter } from 'react-router';
import { AppShell } from '@/app/app-shell';

/**
 * The route table is intentionally empty below the shell: todos 23, 28, 35, 41
 * and 48 register the module screens. `createBrowserRouter` (not
 * `react-router-dom`, which v8 removed) is the v8 entry point.
 */
export const router = createBrowserRouter([
    {
        path: '/',
        element: <AppShell />,
    },
]);
