import { Link } from '@inertiajs/react';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import type { NavItem } from '@/types';

export function NavMain({ items = [] }: { items: NavItem[] }) {
    const { isCurrentUrl } = useCurrentUrl();

    return (
        <SidebarGroup id="nav-main-group" className="px-2 py-0">
            <SidebarGroupLabel id="nav-main-label">Platform</SidebarGroupLabel>
            <SidebarMenu id="nav-main-menu">
                {items.map((item, index) => (
                    <SidebarMenuItem id={`nav-main-item-${index}`} key={item.title}>
                        <SidebarMenuButton
                            id={`nav-main-button-${index}`}
                            asChild
                            isActive={isCurrentUrl(item.href)}
                            tooltip={{ children: item.title }}
                        >
                            <Link id={`nav-main-link-${index}`} href={item.href} prefetch>
                                {item.icon && <item.icon id={`nav-main-icon-${index}`} />}
                                <span>{item.title}</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                ))}
            </SidebarMenu>
        </SidebarGroup>
    );
}
