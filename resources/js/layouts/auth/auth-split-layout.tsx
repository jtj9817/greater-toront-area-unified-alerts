import { Link, usePage } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { home } from '@/routes';
import type { AuthLayoutProps, SharedData } from '@/types';

export default function AuthSplitLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    const { name } = usePage<SharedData>().props;

    return (
        <div
            id="auth-split-layout"
            className="relative grid h-dvh flex-col items-center justify-center px-8 sm:px-0 lg:max-w-none lg:grid-cols-2 lg:px-0"
        >
            <div
                id="auth-split-left-panel"
                className="relative hidden h-full flex-col bg-muted p-10 text-white lg:flex dark:border-r"
            >
                <div
                    id="auth-split-bg"
                    className="absolute inset-0 bg-zinc-900"
                />
                <Link
                    id="auth-split-logo-link"
                    href={home()}
                    className="relative z-20 flex items-center text-lg font-medium"
                >
                    <AppLogoIcon
                        id="auth-split-logo-icon"
                        className="mr-2 size-8 fill-current text-white"
                    />
                    {name}
                </Link>
            </div>
            <div id="auth-split-right-panel" className="w-full lg:p-8">
                <div
                    id="auth-split-content"
                    className="mx-auto flex w-full flex-col justify-center space-y-6 sm:w-[350px]"
                >
                    <Link
                        id="auth-split-mobile-logo-link"
                        href={home()}
                        className="relative z-20 flex items-center justify-center lg:hidden"
                    >
                        <AppLogoIcon
                            id="auth-split-mobile-logo-icon"
                            className="h-10 fill-current text-black sm:h-12"
                        />
                    </Link>
                    <div
                        id="auth-split-header"
                        className="flex flex-col items-start gap-2 text-left sm:items-center sm:text-center"
                    >
                        <h1
                            id="auth-split-title"
                            className="text-xl font-medium"
                        >
                            {title}
                        </h1>
                        <p
                            id="auth-split-description"
                            className="text-sm text-balance text-muted-foreground"
                        >
                            {description}
                        </p>
                    </div>
                    {children}
                </div>
            </div>
        </div>
    );
}
