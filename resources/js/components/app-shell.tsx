import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { SidebarProvider } from '@/components/ui/sidebar';
import type { SharedData } from '@/types';

type Props = {
    children: ReactNode;
    variant?: 'header' | 'sidebar';
};

export function AppShell({
    children,
    variant = 'header',
    id,
}: Props & { id?: string }) {
    const isOpen = usePage<SharedData>().props.sidebarOpen;

    if (variant === 'header') {
        return (
            <div
                id={id || 'app-shell-header'}
                className="flex min-h-screen w-full flex-col"
            >
                {children}
            </div>
        );
    }

    return (
        <SidebarProvider id={id || 'app-shell-sidebar'} defaultOpen={isOpen}>
            {children}
        </SidebarProvider>
    );
}
