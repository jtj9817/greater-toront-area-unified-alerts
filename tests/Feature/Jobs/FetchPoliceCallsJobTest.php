<?php

use App\Jobs\FetchPoliceCallsJob;
use Illuminate\Support\Facades\Artisan;

test('it calls the police fetch-calls artisan command', function () {
    Artisan::shouldReceive('call')
        ->once()
        ->with('police:fetch-calls')
        ->andReturn(0);

    $job = new FetchPoliceCallsJob;
    $job->handle();
});

test('it has correct retry configuration', function () {
    $job = new FetchPoliceCallsJob;

    expect($job->tries)->toBe(3);
    expect($job->backoff)->toBe(30);
});
