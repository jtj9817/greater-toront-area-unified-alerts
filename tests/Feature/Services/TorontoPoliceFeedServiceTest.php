<?php

use App\Services\TorontoPoliceFeedService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

test('it parses a valid arcgis response into normalized records', function () {
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
                        'OCCURRENCE_TIME' => 1706733600000,
                    ],
                ],
            ],
            'exceededTransferLimit' => false,
        ]),
    ]);

    $service = new TorontoPoliceFeedService;
    $results = $service->fetch();

    expect($results)->toHaveCount(1);
    expect($results[0]['object_id'])->toBe(123);
    expect($results[0]['call_type_code'])->toBe('BREPR');
    expect($results[0]['call_type'])->toBe('BREAK & ENTER IN PROGRESS');
    expect($results[0]['division'])->toBe('D42');
    expect($results[0]['cross_streets'])->toBe('BAY ST - YORK ST');
    expect($results[0]['occurrence_time'])->toBeInstanceOf(Carbon::class);
    expect($results[0]['occurrence_time']->toDateTimeString())->toBe('2024-01-31 20:40:00');
});

test('it handles pagination when exceededTransferLimit is true', function () {
    Http::fake([
        '*' => Http::sequence()
            ->push([
                'features' => [
                    ['attributes' => ['OBJECTID' => 1, 'CALL_TYPE_CODE' => 'A', 'CALL_TYPE' => 'Type A', 'DIVISION' => 'D11', 'CROSS_STREETS' => 'A ST - B ST', 'LATITUDE' => 43.65, 'LONGITUDE' => -79.38, 'OCCURRENCE_TIME' => 1706733600000]],
                ],
                'exceededTransferLimit' => true,
            ])
            ->push([
                'features' => [
                    ['attributes' => ['OBJECTID' => 2, 'CALL_TYPE_CODE' => 'B', 'CALL_TYPE' => 'Type B', 'DIVISION' => 'D22', 'CROSS_STREETS' => 'C ST - D ST', 'LATITUDE' => 43.70, 'LONGITUDE' => -79.40, 'OCCURRENCE_TIME' => 1706733600000]],
                ],
                'exceededTransferLimit' => false,
            ]),
    ]);

    $service = new TorontoPoliceFeedService;
    $results = $service->fetch();

    expect($results)->toHaveCount(2);
    expect($results[0]['object_id'])->toBe(1);
    expect($results[1]['object_id'])->toBe(2);
});

test('it throws exception on http error', function () {
    Http::fake([
        '*' => Http::response([], 500),
    ]);

    $service = new TorontoPoliceFeedService;
    $service->fetch();
})->throws(RuntimeException::class, 'Failed to fetch police calls: 500');

test('it throws exception on missing features key', function () {
    Http::fake([
        '*' => Http::response(['error' => 'something went wrong']),
    ]);

    $service = new TorontoPoliceFeedService;
    $service->fetch();
})->throws(RuntimeException::class, "Unexpected API response format: 'features' key missing.");

test('it handles missing optional fields', function () {
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
                    ],
                ],
            ],
        ]),
    ]);

    $service = new TorontoPoliceFeedService;
    $results = $service->fetch();

    expect($results[0]['division'])->toBeNull();
    expect($results[0]['cross_streets'])->toBeNull();
    expect($results[0]['latitude'])->toBeNull();
    expect($results[0]['longitude'])->toBeNull();
});

test('it returns empty array for empty features', function () {
    config(['feeds.allow_empty_feeds' => true]);

    Http::fake([
        '*' => Http::response([
            'features' => [],
        ]),
    ]);

    $service = new TorontoPoliceFeedService;
    $results = $service->fetch();

    expect($results)->toBeEmpty();
});

test('it throws exception on empty features when empty feeds are not allowed', function () {
    config(['feeds.allow_empty_feeds' => false]);

    Http::fake([
        '*' => Http::response([
            'features' => [],
            'exceededTransferLimit' => false,
        ]),
    ]);

    $service = new TorontoPoliceFeedService;
    $service->fetch();
})->throws(RuntimeException::class, 'Toronto Police feed returned an empty features array on the first page');

test('it returns partial results when pagination fails mid-stream', function () {
    Http::fake([
        '*' => Http::sequence()
            ->push([
                'features' => [
                    ['attributes' => ['OBJECTID' => 1, 'CALL_TYPE_CODE' => 'A', 'CALL_TYPE' => 'Type A', 'DIVISION' => 'D11', 'CROSS_STREETS' => 'A ST - B ST', 'LATITUDE' => 43.65, 'LONGITUDE' => -79.38, 'OCCURRENCE_TIME' => 1706733600000]],
                ],
                'exceededTransferLimit' => true,
            ])
            ->push([], 500)
            ->push([], 500)
            ->push([], 500),
    ]);

    $service = new TorontoPoliceFeedService;
    $results = $service->fetch();

    expect($results)->toHaveCount(1);
    expect($results[0]['object_id'])->toBe(1);
    expect($service->lastFetchWasPartial())->toBeTrue();
});

test('it enforces a safety max record limit to avoid unbounded pagination memory growth', function () {
    config([
        'cache.default' => 'array',
        'feeds.police.max_records' => 1,
        'feeds.circuit_breaker.enabled' => false,
    ]);

    Http::fake([
        '*' => Http::response([
            'features' => [
                ['attributes' => ['OBJECTID' => 1, 'CALL_TYPE_CODE' => 'A', 'CALL_TYPE' => 'Type A', 'DIVISION' => 'D11', 'CROSS_STREETS' => 'A ST - B ST', 'LATITUDE' => 43.65, 'LONGITUDE' => -79.38, 'OCCURRENCE_TIME' => 1706733600000]],
                ['attributes' => ['OBJECTID' => 2, 'CALL_TYPE_CODE' => 'B', 'CALL_TYPE' => 'Type B', 'DIVISION' => 'D22', 'CROSS_STREETS' => 'C ST - D ST', 'LATITUDE' => 43.70, 'LONGITUDE' => -79.40, 'OCCURRENCE_TIME' => 1706733600000]],
            ],
            'exceededTransferLimit' => false,
        ], 200),
    ]);

    $service = new TorontoPoliceFeedService;
    $service->fetch();
})->throws(RuntimeException::class, 'Toronto Police feed exceeded safety limit of 1 records');
