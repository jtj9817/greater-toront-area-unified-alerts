import { AppContent } from '@/components/app-content';
import { AppHeader } from '@/components/app-header';
import { AppShell } from '@/components/app-shell';
import type { AppLayoutProps } from '@/types';

export default function AppHeaderLayout({
    children,
    breadcrumbs,
}: AppLayoutProps) {
    return (
        <AppShell id="app-header-shell">
            <AppHeader id="app-header" breadcrumbs={breadcrumbs} />
            <AppContent id="app-header-content">{children}</AppContent>
        </AppShell>
    );
}
