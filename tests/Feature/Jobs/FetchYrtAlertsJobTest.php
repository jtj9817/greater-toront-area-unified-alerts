<?php

use App\Jobs\FetchYrtAlertsJob;
use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Artisan;

test('it calls the yrt fetch-alerts artisan command', function () {
    Artisan::shouldReceive('call')
        ->once()
        ->with('yrt:fetch-alerts')
        ->andReturn(0);

    $job = new FetchYrtAlertsJob;
    $job->handle();
});

test('it throws when the yrt command fails so the job can retry', function () {
    Artisan::shouldReceive('call')
        ->once()
        ->with('yrt:fetch-alerts')
        ->andReturn(1);

    $job = new FetchYrtAlertsJob;
    $job->handle();
})->throws(RuntimeException::class, 'yrt:fetch-alerts failed with exit code 1');

test('it has correct retry configuration', function () {
    config(['queue.unique_lock_store' => 'array']);

    $job = new FetchYrtAlertsJob;

    expect($job->tries)->toBe(3);
    expect($job->backoff)->toBe(30);
    expect($job->timeout)->toBe(120);
    expect($job->uniqueFor)->toBe(600);
    expect($job)->toBeInstanceOf(ShouldBeUnique::class);
    expect($job->uniqueId())->toBe('fetch-yrt-alerts');
    expect($job->uniqueVia()->getStore())->toBeInstanceOf(ArrayStore::class);
});

test('it uses without overlapping job middleware', function () {
    $job = new FetchYrtAlertsJob;
    $middleware = $job->middleware();

    expect($middleware)->toHaveCount(1);
    expect($middleware[0])->toBeInstanceOf(WithoutOverlapping::class);
    expect($middleware[0]->key)->toBe('fetch-yrt-alerts');
    expect($middleware[0]->releaseAfter)->toBe(30);
    expect($middleware[0]->expiresAfter)->toBe(600);
});
