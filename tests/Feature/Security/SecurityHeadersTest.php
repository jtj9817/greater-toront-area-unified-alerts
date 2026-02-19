<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('security headers are present in response', function () {
    $response = $this->get('/');

    $response->assertSuccessful();

    $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(self)');
});

test('hsts header is present when secure', function () {
    // Simulate a secure request
    $this->app['request']->server->set('HTTPS', 'on');

    $response = $this->get('/');

    $response->assertSuccessful();
    $response->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});
