<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

test('security headers use strict runtime-aware csp without hot mode', function () {
    withHotFile(null, function (): void {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertHeader('X-XSS-Protection', '1; mode=block');

        $csp = (string) $response->headers->get('Content-Security-Policy');
        $nonce = extractNonceFromHtml($response->getContent());

        expect($csp)->toContain("default-src 'self'")
            ->and($csp)->toContain("script-src 'self' 'nonce-{$nonce}'")
            ->and($csp)->not->toContain("'unsafe-inline'")
            ->and($csp)->not->toContain("'unsafe-eval'")
            ->and($csp)->toContain("style-src 'self' 'nonce-{$nonce}'")
            ->and($csp)->toContain('https://fonts.googleapis.com')
            ->and($csp)->toContain('https://fonts.bunny.net')
            ->and($csp)->toContain("img-src 'self' data: https:")
            ->and($csp)->toContain("font-src 'self' data:")
            ->and($csp)->toContain('https://fonts.gstatic.com')
            ->and($csp)->toContain("connect-src 'self'")
            ->and($csp)->toContain("frame-ancestors 'self'")
            ->and($csp)->toContain("form-action 'self'")
            ->and($csp)->not->toContain('http://[::1]:5174')
            ->and($csp)->not->toContain('ws://[::1]:5174');
    });
});

test('security headers allow vite hot origins only when hot mode is active', function () {
    withHotFile('http://[::1]:5174', function (): void {
        $response = $this->get('/');

        $response->assertOk();

        $csp = (string) $response->headers->get('Content-Security-Policy');
        $html = $response->getContent();
        $nonce = extractNonceFromHtml($response->getContent());

        expect($csp)->toContain("script-src 'self' 'nonce-{$nonce}' 'unsafe-eval' http://[::1]:5174")
            ->and($csp)->toContain("style-src 'self' 'nonce-{$nonce}' https://fonts.googleapis.com https://fonts.bunny.net 'unsafe-inline'")
            ->and($csp)->toContain("connect-src 'self' http://[::1]:5174 ws://[::1]:5174")
            ->and(app(Vite::class)->cspNonce())->toBe($nonce)
            ->and($html)->toContain('<script type="module" nonce="'.$nonce.'">')
            ->and($html)->toContain("import RefreshRuntime from 'http://[::1]:5174/@react-refresh'");
    });
});

test('security headers keep frontend broadcast websocket hosts in connect-src outside hot mode', function () {
    Config::set('broadcasting.frontend.echo', [
        'key' => 'test-key',
        'cluster' => 'eu',
        'host' => '',
        'port' => '443',
        'scheme' => 'https',
    ]);

    withHotFile(null, function (): void {
        $response = $this->get('/');

        $response->assertOk();

        $csp = (string) $response->headers->get('Content-Security-Policy');

        expect($csp)->toContain("connect-src 'self' https://ws-eu.pusher.com wss://ws-eu.pusher.com")
            ->and($csp)->not->toContain("'unsafe-eval'")
            ->and($csp)->not->toContain('http://[::1]:5174');
    });
});

test('existing security headers are preserved', function () {
    $response = $this->get('/');

    $response->assertOk();
    $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(self)');
});

test('hsts header is present when secure', function () {
    // In test environment, Request::isSecure() might depend on trusted proxies or specific server vars.
    // The most reliable way in Laravel tests is often to force the scheme in the request.

    // We can simulate HTTPS by setting the server variables explicitly that Symfony's Request looks for.
    $response = $this->get('/', ['HTTPS' => 'on']);

    // Alternatively, we can mock the request or environment, but passing headers/server vars is cleaner.
    // Let's try passing X-Forwarded-Proto if the app trusts proxies (common in Sail/Docker).
    // Or just use the full https URL.
    $response = $this->get('https://localhost/');

    $response->assertOk();
    $response->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

function withHotFile(?string $value, callable $callback): void
{
    $hotPath = public_path('hot');
    $hadExisting = File::exists($hotPath);
    $existingContent = $hadExisting ? File::get($hotPath) : null;

    try {
        if ($value === null) {
            File::delete($hotPath);
        } else {
            File::put($hotPath, $value);
        }

        $callback();
    } finally {
        if ($hadExisting) {
            File::put($hotPath, (string) $existingContent);
        } else {
            File::delete($hotPath);
        }
    }
}

// ============================================================================
// Phase 3: CSP/HSTS Branch Coverage Tests
// ============================================================================

// --- Task 1: Hot mode invalid/empty hot file yields no extra CSP origins ---

test('hot file with invalid content yields no extra CSP origins', function () {
    withHotFile('not a url', function (): void {
        $response = $this->get('/');

        $response->assertOk();

        $csp = (string) $response->headers->get('Content-Security-Policy');

        expect($csp)->not->toContain("'unsafe-eval'")
            ->and($csp)->not->toContain("'unsafe-inline'")
            ->and($csp)->not->toContain('not a url');
    });
});

test('hot file with empty string yields no extra CSP origins', function () {
    withHotFile('', function (): void {
        $response = $this->get('/');

        $response->assertOk();

        $csp = (string) $response->headers->get('Content-Security-Policy');

        expect($csp)->not->toContain("'unsafe-eval'")
            ->and($csp)->not->toContain("'unsafe-inline'");
    });
});

// --- Task 2: Broadcast Echo connect-src origin building edge cases ---

test('broadcast echo with explicit host URL form normalizes via parse_url', function () {
    Config::set('broadcasting.frontend.echo', [
        'key' => 'test-key',
        'cluster' => 'mt1',
        'host' => 'https://custom.pusher.example.com',
        'port' => '443',
        'scheme' => 'https',
    ]);

    withHotFile(null, function (): void {
        $response = $this->get('/');

        $response->assertOk();

        $csp = (string) $response->headers->get('Content-Security-Policy');

        // normalizeConfiguredHost strips scheme via parse_url, leaving just 'custom.pusher.example.com'
        // buildOrigin('https', 'custom.pusher.example.com', null) => 'https://custom.pusher.example.com'
        // port 443 for https => omitted (default)
        expect($csp)->toContain('https://custom.pusher.example.com')
            ->and($csp)->toContain('wss://custom.pusher.example.com')
            ->and($csp)->not->toContain(':443');
    });
});

test('broadcast echo with non-default port includes port in connect-src', function () {
    Config::set('broadcasting.frontend.echo', [
        'key' => 'test-key',
        'cluster' => 'mt1',
        'host' => 'my-ws.example.com',
        'port' => '8080',
        'scheme' => 'https',
    ]);

    withHotFile(null, function (): void {
        $response = $this->get('/');

        $response->assertOk();

        $csp = (string) $response->headers->get('Content-Security-Policy');

        expect($csp)->toContain('https://my-ws.example.com:8080')
            ->and($csp)->toContain('wss://my-ws.example.com:8080');
    });
});

test('broadcast echo with ipv6 host brackets host in connect-src', function () {
    Config::set('broadcasting.frontend.echo', [
        'key' => 'test-key',
        'cluster' => 'mt1',
        'host' => '::1',
        'port' => '6001',
        'scheme' => 'http',
    ]);

    withHotFile(null, function (): void {
        $response = $this->get('/');

        $response->assertOk();

        $csp = (string) $response->headers->get('Content-Security-Policy');

        // IPv6 hosts with ':' get bracket-wrapped
        expect($csp)->toContain('http://[::1]:6001')
            ->and($csp)->toContain('ws://[::1]:6001');
    });
});

test('broadcast echo with invalid scheme yields no broadcast origins', function () {
    Config::set('broadcasting.frontend.echo', [
        'key' => 'test-key',
        'cluster' => 'mt1',
        'host' => 'example.com',
        'port' => '443',
        'scheme' => 'ftp',
    ]);

    withHotFile(null, function (): void {
        $response = $this->get('/');

        $response->assertOk();

        $csp = (string) $response->headers->get('Content-Security-Policy');

        expect($csp)->not->toContain('example.com')
            ->and($csp)->not->toContain('ftp://');
    });
});

test('broadcast echo with empty host uses cluster-based default', function () {
    Config::set('broadcasting.frontend.echo', [
        'key' => 'test-key',
        'cluster' => 'eu',
        'host' => '',
        'port' => null,
        'scheme' => 'https',
    ]);

    withHotFile(null, function (): void {
        $response = $this->get('/');

        $response->assertOk();

        $csp = (string) $response->headers->get('Content-Security-Policy');

        // Empty host => falls back to ws-{cluster}.pusher.com
        expect($csp)->toContain('https://ws-eu.pusher.com')
            ->and($csp)->toContain('wss://ws-eu.pusher.com');
    });
});

test('broadcast echo with non-string host falls back to cluster default origin', function () {
    Config::set('broadcasting.frontend.echo', [
        'key' => 'test-key',
        'cluster' => 'mt1',
        'host' => 12345,
        'port' => '443',
        'scheme' => 'https',
    ]);

    withHotFile(null, function (): void {
        $response = $this->get('/');

        $response->assertOk();

        $csp = (string) $response->headers->get('Content-Security-Policy');

        // Non-string host => normalizeConfiguredHost returns null => buildOrigin skipped
        // But empty host => falls back to cluster default
        expect($csp)->toContain('https://ws-mt1.pusher.com');
    });
});

test('broadcast echo with empty key yields no broadcast origins', function () {
    Config::set('broadcasting.frontend.echo', [
        'key' => '',
        'cluster' => 'eu',
        'host' => 'example.com',
        'port' => '443',
        'scheme' => 'https',
    ]);

    withHotFile(null, function (): void {
        $response = $this->get('/');

        $response->assertOk();

        $csp = (string) $response->headers->get('Content-Security-Policy');

        expect($csp)->not->toContain('example.com')
            ->and($csp)->not->toContain('wss://');
    });
});

test('broadcast echo with port as int zero is treated as null', function () {
    Config::set('broadcasting.frontend.echo', [
        'key' => 'test-key',
        'cluster' => 'mt1',
        'host' => 'example.com',
        'port' => 0,
        'scheme' => 'https',
    ]);

    withHotFile(null, function (): void {
        $response = $this->get('/');

        $response->assertOk();

        $csp = (string) $response->headers->get('Content-Security-Policy');

        // port 0 => normalizePort returns null => no port appended
        expect($csp)->toContain('https://example.com')
            ->and($csp)->toContain('wss://example.com')
            ->and($csp)->not->toContain(':0');
    });
});

test('broadcast echo with non-string non-int port is treated as null', function () {
    Config::set('broadcasting.frontend.echo', [
        'key' => 'test-key',
        'cluster' => 'mt1',
        'host' => 'example.com',
        'port' => ['not', 'a', 'port'],
        'scheme' => 'https',
    ]);

    withHotFile(null, function (): void {
        $response = $this->get('/');

        $response->assertOk();

        $csp = (string) $response->headers->get('Content-Security-Policy');

        expect($csp)->toContain('https://example.com')
            ->and($csp)->not->toContain(':[');
    });
});

test('broadcast echo with invalid host URL parse_url returns no origins', function () {
    Config::set('broadcasting.frontend.echo', [
        'key' => 'test-key',
        'cluster' => 'mt1',
        'host' => '://missing-scheme',
        'port' => '443',
        'scheme' => 'https',
    ]);

    withHotFile(null, function (): void {
        $response = $this->get('/');

        $response->assertOk();

        $csp = (string) $response->headers->get('Content-Security-Policy');

        // normalizeConfiguredHost returns null => empty host => falls back to cluster
        expect($csp)->toContain('https://ws-mt1.pusher.com');
    });
});

// --- Task 3: HSTS header presence/absence ---

test('hsts header is absent for non-secure request in non-production', function () {
    // Default test environment is 'testing', not 'production'
    // Plain HTTP request should NOT include HSTS
    $response = $this->get('http://localhost/');

    $response->assertOk();
    $response->assertHeaderMissing('Strict-Transport-Security');
});

test('hsts header is present for non-secure request in production environment', function () {
    try {
        app()->detectEnvironment(fn (): string => 'production');

        $response = $this->get('http://localhost/');

        $response->assertOk();
        $response->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    } finally {
        app()->detectEnvironment(fn (): string => 'testing');
    }
});

function extractNonceFromHtml(string $html): string
{
    preg_match('/<script nonce="([^"]+)">/', $html, $matches);

    expect($matches)->toHaveCount(2);
    expect($html)->toContain('<style nonce="'.$matches[1].'">');

    return $matches[1];
}
