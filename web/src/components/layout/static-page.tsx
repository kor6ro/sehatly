import type { ReactNode } from 'react';
import { useNavigate } from 'react-router';
import { ArrowLeft } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { PageHeader } from '@/components/layout/page-header';

/**
 * The shell for a public, static document: one readable column, one way out.
 *
 * Registration links to the terms and the privacy policy before an account exists, so
 * these pages render outside `AppShell` and must stand on their own: no sidebar, no
 * session, no API call. The exit is a back button rather than a destination link because
 * the reader arrived from either the registration form or a consent slot, and both are
 * one step back; a direct visit with no history falls back to the home chooser.
 */
export function StaticPage({
    title,
    description,
    children,
}: {
    title: string;
    description: string;
    children: ReactNode;
}) {
    const navigate = useNavigate();

    return (
        <main className="mx-auto flex min-h-screen w-full max-w-3xl flex-col gap-6 p-4 md:p-6">
            <PageHeader
                title={title}
                description={description}
                action={
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => {
                            if (window.history.length > 1) {
                                void navigate(-1);

                                return;
                            }

                            void navigate('/');
                        }}
                    >
                        <ArrowLeft />

                        Kembali
                    </Button>
                }
            />

            <Card>
                <CardContent className="flex flex-col gap-6 pt-6">
                    {children}
                </CardContent>
            </Card>
        </main>
    );
}
