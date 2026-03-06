<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class EnsureSecurityHeaders
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = base64_encode(random_bytes(16));
        $request->attributes->set('csp_nonce', $nonce);
        Vite::useCspNonce($nonce);

        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(self)');

        // Sentinel: Added X-XSS-Protection for defense in depth (legacy browsers)
        $response->headers->set('X-XSS-Protection', '1; mode=block');

        $response->headers->set('Content-Security-Policy', $this->buildContentSecurityPolicy($nonce));

        if ($this->shouldAddHsts($request)) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    private function shouldAddHsts(Request $request): bool
    {
        // Add HSTS if the request is secure or if we are in production (behind a proxy that terminates SSL)
        return $request->isSecure() || app()->isProduction();
    }

    private function buildContentSecurityPolicy(string $nonce): string
    {
        $hotOrigins = $this->hotOrigins();

        $scriptSrc = ["'self'", "'nonce-{$nonce}'"];
        $styleSrc = ["'self'", "'nonce-{$nonce}'", 'https://fonts.googleapis.com', 'https://fonts.bunny.net'];
        $connectSrc = ["'self'", ...$this->broadcastConnectOrigins()];

        if ($hotOrigins !== []) {
            $scriptSrc[] = "'unsafe-eval'";
            $styleSrc[] = "'unsafe-inline'";
            foreach ($hotOrigins as $hotOrigin) {
                if (str_starts_with($hotOrigin, 'http://') || str_starts_with($hotOrigin, 'https://')) {
                    $scriptSrc[] = $hotOrigin;
                }

                $connectSrc[] = $hotOrigin;
            }
        }

        $directives = [
            'default-src' => ["'self'"],
            'script-src' => array_values(array_unique($scriptSrc)),
            'style-src' => array_values(array_unique($styleSrc)),
            'img-src' => ["'self'", 'data:', 'https:'],
            'font-src' => ["'self'", 'data:', 'https://fonts.gstatic.com', 'https://fonts.bunny.net'],
            'connect-src' => array_values(array_unique($connectSrc)),
            'frame-ancestors' => ["'self'"],
            'form-action' => ["'self'"],
        ];

        return collect($directives)
            ->map(
                static fn (array $sources, string $directive): string => $directive.' '.implode(' ', $sources)
            )
            ->implode('; ').';';
    }

    /**
     * @return array<int, string>
     */
    private function broadcastConnectOrigins(): array
    {
        $echoConfig = config('broadcasting.frontend.echo', []);
        $key = trim((string) ($echoConfig['key'] ?? ''));

        if ($key === '') {
            return [];
        }

        $cluster = trim((string) ($echoConfig['cluster'] ?? 'mt1'));
        $scheme = strtolower(trim((string) ($echoConfig['scheme'] ?? 'https')));
        $host = $this->normalizeConfiguredHost($echoConfig['host'] ?? null);
        $port = $this->normalizePort($echoConfig['port'] ?? null);

        if ($host === null) {
            $host = 'ws-'.$cluster.'.pusher.com';
        }

        $origin = $this->buildOrigin($scheme, $host, $port);

        if ($origin === null) {
            return [];
        }

        return array_values(array_unique(array_filter([
            $origin,
            $this->toWebsocketOrigin($origin),
        ])));
    }

    /**
     * @return array<int, string>
     */
    private function hotOrigins(): array
    {
        $hotPath = public_path('hot');

        if (! File::exists($hotPath)) {
            return [];
        }

        $hotUrl = trim((string) File::get($hotPath));

        if ($hotUrl === '') {
            return [];
        }

        $origin = $this->parseOrigin($hotUrl);

        if ($origin === null) {
            return [];
        }

        $websocketOrigin = $this->toWebsocketOrigin($origin);

        return array_values(array_unique(array_filter([$origin, $websocketOrigin])));
    }

    private function buildOrigin(string $scheme, string $host, ?int $port): ?string
    {
        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $formattedHost = $this->formatHost($host);

        if ($formattedHost === null) {
            return null;
        }

        $normalizedPort = match ([$scheme, $port]) {
            ['http', 80], ['https', 443] => null,
            default => $port,
        };

        return strtolower($scheme).'://'.$formattedHost.($normalizedPort !== null ? ':'.$normalizedPort : '');
    }

    private function formatHost(string $host): ?string
    {
        $trimmedHost = trim($host);

        if ($trimmedHost === '') {
            return null;
        }

        return str_contains($trimmedHost, ':') && ! str_starts_with($trimmedHost, '[')
            ? '['.$trimmedHost.']'
            : $trimmedHost;
    }

    private function normalizeConfiguredHost(mixed $host): ?string
    {
        if (! is_string($host)) {
            return null;
        }

        $trimmedHost = trim($host);

        if ($trimmedHost === '') {
            return null;
        }

        if (str_contains($trimmedHost, '://')) {
            $parts = parse_url($trimmedHost);

            return is_array($parts) && isset($parts['host']) && is_string($parts['host'])
                ? $parts['host']
                : null;
        }

        return $trimmedHost;
    }

    private function normalizePort(mixed $port): ?int
    {
        if ($port === null || $port === '') {
            return null;
        }

        if (is_int($port)) {
            return $port > 0 ? $port : null;
        }

        if (! is_string($port)) {
            return null;
        }

        $parsedPort = filter_var($port, FILTER_VALIDATE_INT);

        return is_int($parsedPort) && $parsedPort > 0 ? $parsedPort : null;
    }

    private function parseOrigin(string $url): ?string
    {
        $parts = parse_url($url);

        if ($parts === false) {
            return null;
        }

        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;
        $port = isset($parts['port']) ? (int) $parts['port'] : null;

        if (! is_string($scheme) || ! is_string($host)) {
            return null;
        }

        $host = $this->formatHost($host);

        if ($host === null) {
            return null;
        }

        return strtolower($scheme).'://'.$host.($port !== null ? ':'.$port : '');
    }

    private function toWebsocketOrigin(string $origin): ?string
    {
        if (str_starts_with($origin, 'https://')) {
            return 'wss://'.substr($origin, strlen('https://'));
        }

        if (str_starts_with($origin, 'http://')) {
            return 'ws://'.substr($origin, strlen('http://'));
        }

        return null;
    }
}
