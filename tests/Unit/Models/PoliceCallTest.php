<?php

namespace Tests\Unit\Models;

use App\Models\PoliceCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PoliceCallTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_has_correct_casts()
    {
        $call = new PoliceCall();

        $this->assertEquals([
            'id' => 'int',
            'occurrence_time' => 'datetime',
            'feed_updated_at' => 'datetime',
            'is_active' => 'boolean',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ], $call->getCasts());
    }

    /** @test */
    public function it_has_active_scope()
    {
        PoliceCall::factory()->count(3)->create(['is_active' => true]);
        PoliceCall::factory()->count(2)->create(['is_active' => false]);

        $this->assertEquals(3, PoliceCall::active()->count());
        $this->assertEquals(5, PoliceCall::count());
    }
}
