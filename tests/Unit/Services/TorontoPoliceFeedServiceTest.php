<?php

namespace Tests\Unit\Services;

use App\Services\TorontoPoliceFeedService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class TorontoPoliceFeedServiceTest extends TestCase
{
    protected TorontoPoliceFeedService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TorontoPoliceFeedService();
    }

    /** @test */
    public function it_parses_valid_arcgis_response_into_normalized_records()
    {
        Http::fake([
            '*' => Http::response([
                'features' => [
                    [
                        'attributes' => [
                            'OBJECTID' => 123,
                            'CALL_TYPE_CODE' => 'BREPR',
                            'CALL_TYPE' => 'BREAK & ENTER IN PROGRESS',
                            'DIVISION' => 'D42',
                            'CROSS_STREETS' => 'BAY ST - YORK ST',
                            'LATITUDE' => 43.65,
                            'LONGITUDE' => -79.38,
                            'OCCURRENCE_TIME' => 1706733600000, // 2024-01-31 20:40:00 UTC
                        ]
                    ]
                ],
                'exceededTransferLimit' => false
            ])
        ]);

        $results = $this->service->fetch();

        $this->assertCount(1, $results);
        $this->assertEquals(123, $results[0]['object_id']);
        $this->assertEquals('BREPR', $results[0]['call_type_code']);
        $this->assertEquals('D42', $results[0]['division']);
        $this->assertInstanceOf(Carbon::class, $results[0]['occurrence_time']);
        $this->assertEquals('2024-01-31 20:40:00', $results[0]['occurrence_time']->toDateTimeString());
    }

    /** @test */
    public function it_handles_pagination()
    {
        Http::fake([
            '*' => Http::sequence()
                ->push([
                    'features' => [['attributes' => ['OBJECTID' => 1, 'CALL_TYPE_CODE' => 'A', 'CALL_TYPE' => 'A', 'DIVISION' => '', 'CROSS_STREETS' => '', 'LATITUDE' => 0, 'LONGITUDE' => 0, 'OCCURRENCE_TIME' => 0]]],
                    'exceededTransferLimit' => true
                ])
                ->push([
                    'features' => [['attributes' => ['OBJECTID' => 2, 'CALL_TYPE_CODE' => 'B', 'CALL_TYPE' => 'B', 'DIVISION' => '', 'CROSS_STREETS' => '', 'LATITUDE' => 0, 'LONGITUDE' => 0, 'OCCURRENCE_TIME' => 0]]],
                    'exceededTransferLimit' => false
                ])
        ]);

        $results = $this->service->fetch();

        $this->assertCount(2, $results);
        $this->assertEquals(1, $results[0]['object_id']);
        $this->assertEquals(2, $results[1]['object_id']);
    }

    /** @test */
    public function it_throws_exception_on_http_error()
    {
        Http::fake([
            '*' => Http::response([], 500)
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to fetch police calls: 500');

        $this->service->fetch();
    }

    /** @test */
    public function it_throws_exception_on_missing_features_key()
    {
        Http::fake([
            '*' => Http::response(['error' => 'something went wrong'])
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Unexpected API response format: 'features' key missing.");

        $this->service->fetch();
    }

    /** @test */
    public function it_handles_missing_optional_fields()
    {
        Http::fake([
            '*' => Http::response([
                'features' => [
                    [
                        'attributes' => [
                            'OBJECTID' => 123,
                            'CALL_TYPE_CODE' => 'BREPR',
                            'CALL_TYPE' => 'BREAK & ENTER IN PROGRESS',
                            'DIVISION' => ' ',
                            'CROSS_STREETS' => '',
                            'LATITUDE' => null,
                            'LONGITUDE' => null,
                            'OCCURRENCE_TIME' => 1706733600000,
                        ]
                    ]
                ]
            ])
        ]);

        $results = $this->service->fetch();

        $this->assertNull($results[0]['division']);
        $this->assertNull($results[0]['cross_streets']);
        $this->assertNull($results[0]['latitude']);
        $this->assertNull($results[0]['longitude']);
    }
}
