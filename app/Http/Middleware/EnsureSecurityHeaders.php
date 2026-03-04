<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
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
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(self)');

        // Sentinel: Added X-XSS-Protection for defense in depth (legacy browsers)
        $response->headers->set('X-XSS-Protection', '1; mode=block');

        // Sentinel: Added Content-Security-Policy to mitigate XSS and injection attacks
        // 'unsafe-inline' and 'unsafe-eval' are currently required for Vite/React dev mode compatibility.
        // In a strict production environment, these should be tightened with nonces or hashes.
        $csp = "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:; connect-src 'self' wss:; frame-ancestors 'self'; form-action 'self';";
        $response->headers->set('Content-Security-Policy', $csp);

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
}
