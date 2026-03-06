<?php

use App\Jobs\FetchFireIncidentsJob;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Artisan;

test('it calls the fire fetch-incidents artisan command', function () {
    Artisan::shouldReceive('call')
        ->once()
        ->with('fire:fetch-incidents')
        ->andReturn(0);

    $job = new FetchFireIncidentsJob;
    $job->handle();
});

test('it throws when the fire command fails so the job can retry', function () {
    Artisan::shouldReceive('call')
        ->once()
        ->with('fire:fetch-incidents')
        ->andReturn(1);

    $job = new FetchFireIncidentsJob;
    $job->handle();
})->throws(RuntimeException::class, 'fire:fetch-incidents failed with exit code 1');

test('it has correct retry configuration', function () {
    $job = new FetchFireIncidentsJob;

    expect($job->tries)->toBe(3);
    expect($job->backoff)->toBe(30);
    expect($job->timeout)->toBe(120);
    expect($job->uniqueFor)->toBe(600);
    expect($job)->toBeInstanceOf(ShouldBeUnique::class);
    expect($job->uniqueId())->toBe('fetch-fire-incidents');
});

test('it uses without overlapping job middleware', function () {
    $job = new FetchFireIncidentsJob;
    $middleware = $job->middleware();

    expect($middleware)->toHaveCount(1);
    expect($middleware[0])->toBeInstanceOf(WithoutOverlapping::class);
    expect($middleware[0]->key)->toBe('fetch-fire-incidents');
    expect($middleware[0]->releaseAfter)->toBe(30);
    expect($middleware[0]->expiresAfter)->toBe(600);
});
