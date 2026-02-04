import AppLogoIcon from './app-logo-icon';

export default function AppLogo() {
    return (
        <>
            <div id="app-logo-container" className="flex aspect-square size-8 items-center justify-center rounded-md bg-sidebar-primary text-sidebar-primary-foreground">
                <AppLogoIcon id="app-logo-icon" className="size-5 fill-current text-white dark:text-black" />
            </div>
            <div id="app-logo-text" className="ml-1 grid flex-1 text-left text-sm">
                <span id="app-logo-title" className="mb-0.5 truncate leading-tight font-semibold">
                    Laravel Starter Kit
                </span>
            </div>
        </>
    );
}
