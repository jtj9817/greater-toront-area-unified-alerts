<?php

namespace Tests\Feature\Jobs;

use App\Jobs\FetchPoliceCallsJob;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class FetchPoliceCallsJobTest extends TestCase
{
    /** @test */
    public function it_calls_the_police_fetch_calls_command()
    {
        Artisan::shouldReceive('call')
            ->once()
            ->with('police:fetch-calls')
            ->andReturn(0);

        $job = new FetchPoliceCallsJob();
        $job->handle();
    }
}
