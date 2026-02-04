import { Link } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { home } from '@/routes';

export default function AuthCardLayout({
    children,
    title,
    description,
}: PropsWithChildren<{
    name?: string;
    title?: string;
    description?: string;
}>) {
    return (
        <div
            id="auth-card-layout"
            className="flex min-h-svh flex-col items-center justify-center gap-6 bg-muted p-6 md:p-10"
        >
            <div
                id="auth-card-container"
                className="flex w-full max-w-md flex-col gap-6"
            >
                <Link
                    id="auth-card-logo-link"
                    href={home()}
                    className="flex items-center gap-2 self-center font-medium"
                >
                    <div
                        id="auth-card-logo-container"
                        className="flex h-9 w-9 items-center justify-center"
                    >
                        <AppLogoIcon
                            id="auth-card-logo-icon"
                            className="size-9 fill-current text-black dark:text-white"
                        />
                    </div>
                </Link>

                <div id="auth-card-wrapper" className="flex flex-col gap-6">
                    <Card id="auth-card" className="rounded-xl">
                        <CardHeader
                            id="auth-card-header"
                            className="px-10 pt-8 pb-0 text-center"
                        >
                            <CardTitle id="auth-card-title" className="text-xl">
                                {title}
                            </CardTitle>
                            <CardDescription id="auth-card-description">
                                {description}
                            </CardDescription>
                        </CardHeader>
                        <CardContent
                            id="auth-card-content"
                            className="px-10 py-8"
                        >
                            {children}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </div>
    );
}
