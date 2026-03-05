<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('security headers are present in response', function () {
    $response = $this->get('/');

    $response->assertOk();

    // Verify X-XSS-Protection
    $response->assertHeader('X-XSS-Protection', '1; mode=block');

    // Verify Content-Security-Policy
    $csp = $response->headers->get('Content-Security-Policy');
    expect($csp)->not->toBeNull()
        ->and($csp)->toContain("default-src 'self'")
        ->and($csp)->toContain("script-src 'self' 'unsafe-inline' 'unsafe-eval'")
        ->and($csp)->toContain("style-src 'self' 'unsafe-inline'")
        ->and($csp)->toContain('https://fonts.googleapis.com')
        ->and($csp)->toContain('https://fonts.bunny.net')
        ->and($csp)->toContain("img-src 'self' data: https:")
        ->and($csp)->toContain("font-src 'self' data:")
        ->and($csp)->toContain('https://fonts.gstatic.com')
        ->and($csp)->toContain("frame-ancestors 'self'")
        ->and($csp)->toContain("form-action 'self'");
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
