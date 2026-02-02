<?php

use App\Jobs\FetchFireIncidentsJob;
use Illuminate\Support\Facades\Artisan;

test('it calls the fire fetch-incidents artisan command', function () {
    Artisan::shouldReceive('call')
        ->once()
        ->with('fire:fetch-incidents')
        ->andReturn(0);

    $job = new FetchFireIncidentsJob;
    $job->handle();
});

test('it has correct retry configuration', function () {
    $job = new FetchFireIncidentsJob;

    expect($job->tries)->toBe(3);
    expect($job->backoff)->toBe(30);
});
