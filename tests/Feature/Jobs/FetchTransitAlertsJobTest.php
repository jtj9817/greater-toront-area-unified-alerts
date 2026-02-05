<?php

use App\Jobs\FetchTransitAlertsJob;
use Illuminate\Support\Facades\Artisan;

test('it calls the transit fetch-alerts artisan command', function () {
    Artisan::shouldReceive('call')
        ->once()
        ->with('transit:fetch-alerts')
        ->andReturn(0);

    $job = new FetchTransitAlertsJob;
    $job->handle();
});

test('it has correct retry configuration', function () {
    $job = new FetchTransitAlertsJob;

    expect($job->tries)->toBe(3);
    expect($job->backoff)->toBe(30);
});
