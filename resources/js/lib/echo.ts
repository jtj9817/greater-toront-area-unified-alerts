import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

declare global {
    interface Window {
        Echo?: Echo<'pusher'>;
        Pusher: typeof Pusher;
    }
}

const parsePort = (value: string | undefined, fallback: number): number => {
    const parsedPort = Number.parseInt(value ?? '', 10);

    return Number.isNaN(parsedPort) ? fallback : parsedPort;
};

const resolveWsHost = (host: string | undefined, cluster: string): string => {
    const trimmedHost = host?.trim();

    if (trimmedHost && trimmedHost.length > 0) {
        return trimmedHost;
    }

    return `ws-${cluster}.pusher.com`;
};

const resolveCsrfToken = (): string | null => {
    if (typeof document === 'undefined') {
        return null;
    }

    const token = document
        .querySelector('meta[name="csrf-token"]')
        ?.getAttribute('content');

    return token && token.length > 0 ? token : null;
};

const initializeEcho = (): void => {
    if (typeof window === 'undefined') {
        return;
    }

    const key = import.meta.env.VITE_PUSHER_APP_KEY;

    if (!key) {
        return;
    }

    const scheme =
        import.meta.env.VITE_PUSHER_SCHEME ??
        (window.location.protocol === 'https:' ? 'https' : 'http');
    const forceTls = scheme === 'https';
    const cluster = import.meta.env.VITE_PUSHER_APP_CLUSTER ?? 'mt1';
    const host = resolveWsHost(import.meta.env.VITE_PUSHER_HOST, cluster);
    const port = parsePort(
        import.meta.env.VITE_PUSHER_PORT,
        forceTls ? 443 : 80,
    );
    const csrfToken = resolveCsrfToken();

    window.Pusher = Pusher;
    window.Echo = new Echo({
        broadcaster: 'pusher',
        key,
        cluster,
        wsHost: host,
        wsPort: port,
        wssPort: port,
        forceTLS: forceTls,
        enabledTransports: ['ws', 'wss'],
        authEndpoint: '/broadcasting/auth',
        auth: csrfToken
            ? {
                  headers: {
                      'X-CSRF-TOKEN': csrfToken,
                  },
              }
            : undefined,
    });
};

initializeEcho();
