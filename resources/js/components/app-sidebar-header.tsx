import { Breadcrumbs } from '@/components/breadcrumbs';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function AppSidebarHeader({
    breadcrumbs = [],
    id,
}: {
    breadcrumbs?: BreadcrumbItemType[];
    id?: string;
}) {
    return (
        <header id={id || 'app-sidebar-header'} className="flex h-16 shrink-0 items-center gap-2 border-b border-sidebar-border/50 px-6 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4">
            <div id="app-sidebar-header-content" className="flex items-center gap-2">
                <SidebarTrigger id="app-sidebar-trigger" className="-ml-1" />
                <Breadcrumbs id="app-sidebar-breadcrumbs" breadcrumbs={breadcrumbs} />
            </div>
        </header>
    );
}
