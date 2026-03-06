<?php

use App\Jobs\FetchGoTransitAlertsJob;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Artisan;

test('it calls the go transit fetch-alerts artisan command', function () {
    Artisan::shouldReceive('call')
        ->once()
        ->with('go-transit:fetch-alerts')
        ->andReturn(0);

    $job = new FetchGoTransitAlertsJob;
    $job->handle();
});

test('it throws when the go transit command fails so the job can retry', function () {
    Artisan::shouldReceive('call')
        ->once()
        ->with('go-transit:fetch-alerts')
        ->andReturn(1);

    $job = new FetchGoTransitAlertsJob;
    $job->handle();
})->throws(RuntimeException::class, 'go-transit:fetch-alerts failed with exit code 1');

test('it has correct retry configuration', function () {
    $job = new FetchGoTransitAlertsJob;

    expect($job->tries)->toBe(3);
    expect($job->backoff)->toBe(30);
    expect($job->timeout)->toBe(120);
    expect($job->uniqueFor)->toBe(3600);
    expect($job)->toBeInstanceOf(ShouldBeUnique::class);
    expect($job->uniqueId())->toBe('fetch-go-transit-alerts');
});

test('it uses without overlapping job middleware', function () {
    $job = new FetchGoTransitAlertsJob;
    $middleware = $job->middleware();

    expect($middleware)->toHaveCount(1);
    expect($middleware[0])->toBeInstanceOf(WithoutOverlapping::class);
    expect($middleware[0]->key)->toBe('fetch-go-transit-alerts');
    expect($middleware[0]->releaseAfter)->toBe(30);
    expect($middleware[0]->expiresAfter)->toBe(600);
});
