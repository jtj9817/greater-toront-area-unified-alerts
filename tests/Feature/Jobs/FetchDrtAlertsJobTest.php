<?php

use App\Jobs\FetchDrtAlertsJob;
use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Artisan;

test('it calls the drt fetch-alerts artisan command', function () {
    Artisan::shouldReceive('call')
        ->once()
        ->with('drt:fetch-alerts')
        ->andReturn(0);

    $job = new FetchDrtAlertsJob;
    $job->handle();
});

test('it throws when the drt command fails so the job can retry', function () {
    Artisan::shouldReceive('call')
        ->once()
        ->with('drt:fetch-alerts')
        ->andReturn(1);

    $job = new FetchDrtAlertsJob;
    $job->handle();
})->throws(RuntimeException::class, 'drt:fetch-alerts failed with exit code 1');

test('it has correct retry configuration', function () {
    config(['queue.unique_lock_store' => 'array']);

    $job = new FetchDrtAlertsJob;

    expect($job->tries)->toBe(3);
    expect($job->backoff)->toBe(30);
    expect($job->timeout)->toBe(120);
    expect($job->uniqueFor)->toBe(600);
    expect($job)->toBeInstanceOf(ShouldBeUnique::class);
    expect($job->uniqueId())->toBe('fetch-drt-alerts');
    expect($job->uniqueVia()->getStore())->toBeInstanceOf(ArrayStore::class);
});

test('it uses without overlapping job middleware', function () {
    $job = new FetchDrtAlertsJob;
    $middleware = $job->middleware();

    expect($middleware)->toHaveCount(1);
    expect($middleware[0])->toBeInstanceOf(WithoutOverlapping::class);
    expect($middleware[0]->key)->toBe('fetch-drt-alerts');
    expect($middleware[0]->releaseAfter)->toBe(30);
    expect($middleware[0]->expiresAfter)->toBe(600);
});
