import { Link } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn, toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit } from '@/routes/profile';
import { show } from '@/routes/two-factor';
import { edit as editPassword } from '@/routes/user-password';
import type { NavItem } from '@/types';

const sidebarNavItems: NavItem[] = [
    {
        title: 'Profile',
        href: edit(),
        icon: null,
    },
    {
        title: 'Password',
        href: editPassword(),
        icon: null,
    },
    {
        title: 'Two-Factor Auth',
        href: show(),
        icon: null,
    },
    {
        title: 'Appearance',
        href: editAppearance(),
        icon: null,
    },
];

export default function SettingsLayout({ children }: PropsWithChildren) {
    const { isCurrentUrl } = useCurrentUrl();

    // When server-side rendering, we only render the layout on the client...
    if (typeof window === 'undefined') {
        return null;
    }

    return (
        <div id="settings-layout" className="px-4 py-6">
            <Heading
                id="settings-heading"
                title="Settings"
                description="Manage your profile and account settings"
            />

            <div
                id="settings-container"
                className="flex flex-col lg:flex-row lg:space-x-12"
            >
                <aside
                    id="settings-sidebar"
                    className="w-full max-w-xl lg:w-48"
                >
                    <nav
                        id="settings-nav"
                        className="flex flex-col space-y-1 space-x-0"
                        aria-label="Settings"
                    >
                        {sidebarNavItems.map((item, index) => (
                            <Button
                                id={`settings-nav-item-${index}`}
                                key={`${toUrl(item.href)}-${index}`}
                                size="sm"
                                variant="ghost"
                                asChild
                                className={cn('w-full justify-start', {
                                    'bg-muted': isCurrentUrl(item.href),
                                })}
                            >
                                <Link
                                    id={`settings-nav-link-${index}`}
                                    href={item.href}
                                >
                                    {item.icon && (
                                        <item.icon
                                            id={`settings-nav-icon-${index}`}
                                            className="h-4 w-4"
                                        />
                                    )}
                                    {item.title}
                                </Link>
                            </Button>
                        ))}
                    </nav>
                </aside>

                <Separator id="settings-separator" className="my-6 lg:hidden" />

                <div id="settings-content" className="flex-1 md:max-w-2xl">
                    <section
                        id="settings-section"
                        className="max-w-xl space-y-12"
                    >
                        {children}
                    </section>
                </div>
            </div>
        </div>
    );
}
