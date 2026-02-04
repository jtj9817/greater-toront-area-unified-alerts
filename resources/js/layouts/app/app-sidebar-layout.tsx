import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import type { AppLayoutProps } from '@/types';

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
}: AppLayoutProps) {
    return (
        <AppShell id="app-sidebar-shell" variant="sidebar">
            <AppSidebar />
            <AppContent id="app-sidebar-content" variant="sidebar" className="overflow-x-hidden">
                <AppSidebarHeader id="app-sidebar-header" breadcrumbs={breadcrumbs} />
                {children}
            </AppContent>
        </AppShell>
    );
}
