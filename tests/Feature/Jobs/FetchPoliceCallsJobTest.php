<?php

use App\Jobs\FetchPoliceCallsJob;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Artisan;

test('it calls the police fetch-calls artisan command', function () {
    Artisan::shouldReceive('call')
        ->once()
        ->with('police:fetch-calls')
        ->andReturn(0);

    $job = new FetchPoliceCallsJob;
    $job->handle();
});

test('it throws when the police command fails so the job can retry', function () {
    Artisan::shouldReceive('call')
        ->once()
        ->with('police:fetch-calls')
        ->andReturn(1);

    $job = new FetchPoliceCallsJob;
    $job->handle();
})->throws(RuntimeException::class, 'police:fetch-calls failed with exit code 1');

test('it has correct retry configuration', function () {
    $job = new FetchPoliceCallsJob;

    expect($job->tries)->toBe(3);
    expect($job->backoff)->toBe(30);
    expect($job->timeout)->toBe(120);
    expect($job->uniqueFor)->toBe(3600);
    expect($job)->toBeInstanceOf(ShouldBeUnique::class);
    expect($job->uniqueId())->toBe('fetch-police-calls');
});

test('it uses without overlapping job middleware', function () {
    $job = new FetchPoliceCallsJob;
    $middleware = $job->middleware();

    expect($middleware)->toHaveCount(1);
    expect($middleware[0])->toBeInstanceOf(WithoutOverlapping::class);
    expect($middleware[0]->key)->toBe('fetch-police-calls');
    expect($middleware[0]->releaseAfter)->toBe(30);
    expect($middleware[0]->expiresAfter)->toBe(600);
});
