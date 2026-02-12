<?php

namespace Tests\Unit\Services;

use App\Services\Geocoding\LocalGeocodingService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LocalGeocodingServiceTest extends TestCase
{
    public function test_search_addresses_uses_optimized_query()
    {
        DB::shouldReceive('table')
            ->with('addresses')
            ->once()
            ->andReturnSelf();

        DB::shouldReceive('select')
            ->with('id', 'address', 'latitude', 'longitude')
            ->once()
            ->andReturnSelf();

        // Expect 'like' with 'query%' (no leading wildcard)
        DB::shouldReceive('where')
            ->with('address', 'like', 'foo%')
            ->once()
            ->andReturnSelf();

        DB::shouldReceive('limit')->with(10)->andReturnSelf();
        DB::shouldReceive('get')->andReturn(collect([]));

        $service = new LocalGeocodingService();
        $service->searchAddresses('foo');
    }

    public function test_search_pois_uses_optimized_query()
    {
        DB::shouldReceive('table')
            ->with('pois')
            ->once()
            ->andReturnSelf();

        DB::shouldReceive('select')
            ->with('id', 'name', 'category', 'latitude', 'longitude')
            ->once()
            ->andReturnSelf();

        // Expect 'like' with 'query%'
        DB::shouldReceive('where')
            ->with('name', 'like', 'bar%')
            ->once()
            ->andReturnSelf();

        DB::shouldReceive('limit')->with(10)->andReturnSelf();
        DB::shouldReceive('get')->andReturn(collect([]));

        $service = new LocalGeocodingService();
        $service->searchPois('bar');
    }
}
