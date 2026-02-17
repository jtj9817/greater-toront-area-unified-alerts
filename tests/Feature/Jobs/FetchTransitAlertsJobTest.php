<?php

use App\Jobs\FetchTransitAlertsJob;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Artisan;

test('it calls the transit fetch-alerts artisan command', function () {
    Artisan::shouldReceive('call')
        ->once()
        ->with('transit:fetch-alerts')
        ->andReturn(0);

    $job = new FetchTransitAlertsJob;
    $job->handle();
});

test('it throws when the transit command fails so the job can retry', function () {
    Artisan::shouldReceive('call')
        ->once()
        ->with('transit:fetch-alerts')
        ->andReturn(1);

    $job = new FetchTransitAlertsJob;
    $job->handle();
})->throws(RuntimeException::class, 'transit:fetch-alerts failed with exit code 1');

test('it has correct retry configuration', function () {
    $job = new FetchTransitAlertsJob;

    expect($job->tries)->toBe(3);
    expect($job->backoff)->toBe(30);
    expect($job->timeout)->toBe(120);
});

test('it uses without overlapping job middleware', function () {
    $job = new FetchTransitAlertsJob;
    $middleware = $job->middleware();

    expect($middleware)->toHaveCount(1);
    expect($middleware[0])->toBeInstanceOf(WithoutOverlapping::class);
    expect($middleware[0]->key)->toBe('fetch-transit-alerts');
    expect($middleware[0]->releaseAfter)->toBeNull();
    expect($middleware[0]->expiresAfter)->toBe(600);
});
