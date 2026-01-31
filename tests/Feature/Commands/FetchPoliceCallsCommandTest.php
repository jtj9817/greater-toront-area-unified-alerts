<?php

namespace Tests\Feature\Commands;

use App\Models\PoliceCall;
use App\Services\TorontoPoliceFeedService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class FetchPoliceCallsCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_creates_new_records_from_feed_data()
    {
        $this->mock(TorontoPoliceFeedService::class, function (MockInterface $mock) {
            $mock->shouldReceive('fetch')->once()->andReturn([
                [
                    'object_id' => 123,
                    'call_type_code' => 'BREPR',
                    'call_type' => 'BREAK & ENTER IN PROGRESS',
                    'division' => 'D42',
                    'cross_streets' => 'BAY ST - YORK ST',
                    'latitude' => 43.65,
                    'longitude' => -79.38,
                    'occurrence_time' => Carbon::now(),
                ]
            ]);
        });

        $this->artisan('police:fetch-calls')
            ->expectsOutputToContain('Found 1 calls in the feed')
            ->expectsOutputToContain('Successfully updated police calls')
            ->assertExitCode(0);

        $this->assertDatabaseHas('police_calls', [
            'object_id' => 123,
            'is_active' => true,
        ]);
    }

    /** @test */
    public function it_updates_existing_records()
    {
        PoliceCall::factory()->create([
            'object_id' => 123,
            'call_type' => 'OLD TYPE',
            'is_active' => true,
        ]);

        $this->mock(TorontoPoliceFeedService::class, function (MockInterface $mock) {
            $mock->shouldReceive('fetch')->once()->andReturn([
                [
                    'object_id' => 123,
                    'call_type_code' => 'BREPR',
                    'call_type' => 'NEW TYPE',
                    'division' => 'D42',
                    'cross_streets' => 'BAY ST - YORK ST',
                    'latitude' => 43.65,
                    'longitude' => -79.38,
                    'occurrence_time' => Carbon::now(),
                ]
            ]);
        });

        $this->artisan('police:fetch-calls')->assertExitCode(0);

        $this->assertDatabaseHas('police_calls', [
            'object_id' => 123,
            'call_type' => 'NEW TYPE',
            'is_active' => true,
        ]);
        $this->assertEquals(1, PoliceCall::count());
    }

    /** @test */
    public function it_deactivates_calls_no_longer_in_the_feed()
    {
        PoliceCall::factory()->create([
            'object_id' => 111,
            'is_active' => true,
        ]);

        $this->mock(TorontoPoliceFeedService::class, function (MockInterface $mock) {
            $mock->shouldReceive('fetch')->once()->andReturn([
                [
                    'object_id' => 222,
                    'call_type_code' => 'BREPR',
                    'call_type' => 'BREAK & ENTER IN PROGRESS',
                    'division' => 'D42',
                    'cross_streets' => 'BAY ST - YORK ST',
                    'latitude' => 43.65,
                    'longitude' => -79.38,
                    'occurrence_time' => Carbon::now(),
                ]
            ]);
        });

        $this->artisan('police:fetch-calls')
            ->expectsOutputToContain('Deactivated 1 stale calls')
            ->assertExitCode(0);

        $this->assertDatabaseHas('police_calls', [
            'object_id' => 111,
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('police_calls', [
            'object_id' => 222,
            'is_active' => true,
        ]);
    }

    /** @test */
    public function it_returns_failure_on_service_exception()
    {
        $this->mock(TorontoPoliceFeedService::class, function (MockInterface $mock) {
            $mock->shouldReceive('fetch')->once()->andThrow(new \RuntimeException('API Down'));
        });

        $this->artisan('police:fetch-calls')
            ->expectsOutputToContain('Failed to fetch police calls: API Down')
            ->assertExitCode(1);
    }
}
