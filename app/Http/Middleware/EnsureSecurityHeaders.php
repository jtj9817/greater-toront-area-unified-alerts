<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
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
        $connectSrc = ["'self'"];

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

        $host = str_contains($host, ':') && ! str_starts_with($host, '[')
            ? '['.$host.']'
            : $host;

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
