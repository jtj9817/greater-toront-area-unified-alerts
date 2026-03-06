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

function extractNonceFromHtml(string $html): string
{
    preg_match('/<script nonce="([^"]+)">/', $html, $matches);

    expect($matches)->toHaveCount(2);
    expect($html)->toContain('<style nonce="'.$matches[1].'">');

    return $matches[1];
}
