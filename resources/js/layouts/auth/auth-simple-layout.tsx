import { Link } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    return (
        <div
            id="auth-simple-layout"
            className="flex min-h-svh flex-col items-center justify-center gap-6 bg-background p-6 md:p-10"
        >
            <div id="auth-simple-container" className="w-full max-w-sm">
                <div id="auth-simple-wrapper" className="flex flex-col gap-8">
                    <div
                        id="auth-simple-header"
                        className="flex flex-col items-center gap-4"
                    >
                        <Link
                            id="auth-simple-logo-link"
                            href={home()}
                            className="flex flex-col items-center gap-2 font-medium"
                        >
                            <div
                                id="auth-simple-logo-container"
                                className="mb-1 flex h-9 w-9 items-center justify-center rounded-md"
                            >
                                <AppLogoIcon
                                    id="auth-simple-logo-icon"
                                    className="size-9 fill-current text-[var(--foreground)] dark:text-white"
                                />
                            </div>
                            <span id="auth-simple-sr-title" className="sr-only">
                                {title}
                            </span>
                        </Link>

                        <div
                            id="auth-simple-text"
                            className="space-y-2 text-center"
                        >
                            <h1
                                id="auth-simple-title"
                                className="text-xl font-medium"
                            >
                                {title}
                            </h1>
                            <p
                                id="auth-simple-description"
                                className="text-center text-sm text-muted-foreground"
                            >
                                {description}
                            </p>
                        </div>
                    </div>
                    {children}
                </div>
            </div>
        </div>
    );
}
