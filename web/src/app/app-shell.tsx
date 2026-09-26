import { Outlet } from 'react-router';
import { SidebarProvider } from '@/components/ui/sidebar';
import { Toaster } from '@/components/ui/sonner';

/**
 * Application chrome only. Every screen is owned by a later module todo; the
 * sidebar provider and the toaster are the two app-wide providers the
 * relocated kit requires, so they are mounted here rather than in a screen.
 */
export function AppShell() {
    return (
        <SidebarProvider>
            <Outlet />
            <Toaster />
        </SidebarProvider>
    );
}
