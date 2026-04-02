<?php

declare(strict_types=1);

use App\Models\FireIncident;
use App\Models\MiwayAlert;
use App\Models\PoliceCall;
use App\Models\YrtAlert;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the feed api returns alerts with next_cursor', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-03 12:00:00'));

    // Create 5 fire incidents
    for ($i = 1; $i <= 5; $i++) {
        FireIncident::factory()->create([
            'event_num' => "F{$i}",
            'is_active' => true,
            'dispatch_time' => Carbon::now()->subMinutes($i),
        ]);
    }

    $response = $this->getJson(route('api.feed'));

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'source',
                    'external_id',
                    'is_active',
                    'timestamp',
                    'title',
                    'location',
                    'meta',
                ],
            ],
            'next_cursor',
        ]);
});

test('the feed api respects status filter', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-03 12:00:00'));

    FireIncident::factory()->create([
        'event_num' => 'F1',
        'is_active' => true,
        'dispatch_time' => Carbon::now()->subMinutes(1),
    ]);

    FireIncident::factory()->create([
        'event_num' => 'F2',
        'is_active' => false,
        'dispatch_time' => Carbon::now()->subMinutes(2),
    ]);

    $activeResponse = $this->getJson(route('api.feed', ['status' => 'active']));
    $activeResponse->assertOk();
    $activeData = $activeResponse->json('data');
    expect($activeData)->toHaveCount(1)
        ->and($activeData[0]['external_id'])->toBe('F1');

    $clearedResponse = $this->getJson(route('api.feed', ['status' => 'cleared']));
    $clearedResponse->assertOk();
    $clearedData = $clearedResponse->json('data');
    expect($clearedData)->toHaveCount(1)
        ->and($clearedData[0]['external_id'])->toBe('F2');
});

test('the feed api respects source filter', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-03 12:00:00'));

    FireIncident::factory()->create([
        'event_num' => 'F1',
        'is_active' => true,
        'dispatch_time' => Carbon::now()->subMinutes(1),
    ]);

    PoliceCall::factory()->create([
        'object_id' => 123456,
        'is_active' => true,
        'occurrence_time' => Carbon::now()->subMinutes(2),
    ]);

    $fireResponse = $this->getJson(route('api.feed', ['source' => 'fire']));
    $fireResponse->assertOk();
    $fireData = $fireResponse->json('data');
    expect($fireData)->toHaveCount(1)
        ->and($fireData[0]['source'])->toBe('fire');

    $policeResponse = $this->getJson(route('api.feed', ['source' => 'police']));
    $policeResponse->assertOk();
    $policeData = $policeResponse->json('data');
    expect($policeData)->toHaveCount(1)
        ->and($policeData[0]['source'])->toBe('police');
});

test('the feed api respects source filter for miway', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-03 12:00:00'));

    MiwayAlert::factory()->create([
        'external_id' => 'miway:alert:12345',
        'header_text' => 'Route 17 detour',
        'is_active' => true,
        'starts_at' => Carbon::now()->subMinutes(5),
    ]);

    MiwayAlert::factory()->create([
        'external_id' => 'miway:alert:67890',
        'header_text' => 'Route 5 reduced service',
        'is_active' => false,
        'starts_at' => Carbon::now()->subMinutes(10),
    ]);

    // Filter by source=miway should return all miway alerts (active and inactive)
    $miwayResponse = $this->getJson(route('api.feed', ['source' => 'miway']));
    $miwayResponse->assertOk();
    $miwayData = $miwayResponse->json('data');
    expect($miwayData)->toHaveCount(2)
        ->and($miwayData[0]['source'])->toBe('miway')
        ->and($miwayData[1]['source'])->toBe('miway');

    // Filter by source=miway and status=active should return only active
    $activeResponse = $this->getJson(route('api.feed', ['source' => 'miway', 'status' => 'active']));
    $activeResponse->assertOk();
    $activeData = $activeResponse->json('data');
    expect($activeData)->toHaveCount(1)
        ->and($activeData[0]['source'])->toBe('miway')
        ->and($activeData[0]['is_active'])->toBe(true);
});

test('miway alerts appear in unified feed with correct structure', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-03 12:00:00'));

    MiwayAlert::factory()->create([
        'external_id' => 'miway:alert:99999',
        'header_text' => 'Bus route 101 detour',
        'description_text' => 'Due to construction on Hurontario Street',
        'cause' => 'CONSTRUCTION',
        'effect' => 'DETOUR',
        'is_active' => true,
        'starts_at' => Carbon::now()->subMinutes(5),
        'url' => 'https://www.miway.ca/alerts/101',
        'detour_pdf_url' => 'https://www.miway.ca/detours/101.pdf',
    ]);

    $response = $this->getJson(route('api.feed'));

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'source',
                    'external_id',
                    'is_active',
                    'timestamp',
                    'title',
                    'location',
                    'meta',
                ],
            ],
            'next_cursor',
        ]);

    $data = $response->json('data');
    expect($data)->toHaveCount(1);

    $alert = $data[0];
    expect($alert['source'])->toBe('miway');
    expect($alert['external_id'])->toBe('miway:alert:99999');
    expect($alert['is_active'])->toBe(true);
    expect($alert['title'])->toBe('Bus route 101 detour');
    expect($alert['location'])->toBeNull(); // MiWay has no location

    // Verify meta contains MiWay-specific fields
    expect($alert['meta'])->toBeArray();
    expect($alert['meta']['header_text'])->toBe('Bus route 101 detour');
    expect($alert['meta']['description_text'])->toBe('Due to construction on Hurontario Street');
    expect($alert['meta']['cause'])->toBe('CONSTRUCTION');
    expect($alert['meta']['effect'])->toBe('DETOUR');
    expect($alert['meta']['url'])->toBe('https://www.miway.ca/alerts/101');
    expect($alert['meta']['detour_pdf_url'])->toBe('https://www.miway.ca/detours/101.pdf');
});

test('source filter returns empty for miway when no miway alerts exist', function () {
    FireIncident::factory()->create([
        'event_num' => 'F1',
        'is_active' => true,
    ]);

    $response = $this->getJson(route('api.feed', ['source' => 'miway']));
    $response->assertOk();

    $data = $response->json('data');
    expect($data)->toHaveCount(0);
});

test('the feed api respects source and status filters for yrt without affecting existing sources', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-03 12:00:00'));

    FireIncident::factory()->create([
        'event_num' => 'F1',
        'is_active' => true,
        'dispatch_time' => Carbon::now()->subMinutes(2),
    ]);

    YrtAlert::factory()->create([
        'external_id' => '91001',
        'is_active' => true,
        'posted_at' => Carbon::now()->subMinutes(1),
    ]);

    YrtAlert::factory()->create([
        'external_id' => '91002',
        'is_active' => false,
        'posted_at' => Carbon::now()->subMinutes(3),
    ]);

    $yrtResponse = $this->getJson(route('api.feed', ['source' => 'yrt']));
    $yrtResponse->assertOk();
    $yrtData = $yrtResponse->json('data');

    expect($yrtData)->toHaveCount(2)
        ->and(collect($yrtData)->every(fn (array $row): bool => $row['source'] === 'yrt'))->toBeTrue();

    $yrtActiveResponse = $this->getJson(route('api.feed', ['source' => 'yrt', 'status' => 'active']));
    $yrtActiveResponse->assertOk();
    $yrtActiveData = $yrtActiveResponse->json('data');

    expect($yrtActiveData)->toHaveCount(1)
        ->and($yrtActiveData[0]['source'])->toBe('yrt')
        ->and($yrtActiveData[0]['external_id'])->toBe('91001')
        ->and($yrtActiveData[0]['is_active'])->toBeTrue();

    $fireResponse = $this->getJson(route('api.feed', ['source' => 'fire']));
    $fireResponse->assertOk();
    $fireData = $fireResponse->json('data');

    expect($fireData)->toHaveCount(1)
        ->and($fireData[0]['source'])->toBe('fire')
        ->and($fireData[0]['external_id'])->toBe('F1');
});

test('the feed api respects since filter', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-03 12:00:00'));

    // Create an alert from 5 minutes ago
    FireIncident::factory()->create([
        'event_num' => 'F1',
        'is_active' => true,
        'dispatch_time' => Carbon::now()->subMinutes(5),
    ]);

    // Create an alert from 2 hours ago
    FireIncident::factory()->create([
        'event_num' => 'F2',
        'is_active' => true,
        'dispatch_time' => Carbon::now()->subHours(2),
    ]);

    $response = $this->getJson(route('api.feed', ['since' => '30m']));
    $response->assertOk();
    $data = $response->json('data');
    expect($data)->toHaveCount(1)
        ->and($data[0]['external_id'])->toBe('F1');
});

test('the feed api respects sort direction', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-03 12:00:00'));

    FireIncident::factory()->create([
        'event_num' => 'F1',
        'is_active' => true,
        'dispatch_time' => Carbon::now()->subMinutes(1),
    ]);

    FireIncident::factory()->create([
        'event_num' => 'F2',
        'is_active' => true,
        'dispatch_time' => Carbon::now()->subMinutes(2),
    ]);

    $descResponse = $this->getJson(route('api.feed', ['sort' => 'desc']));
    $descResponse->assertOk();
    $descExternalIds = array_column($descResponse->json('data') ?? [], 'external_id');
    expect($descExternalIds)->toBe(['F1', 'F2']);

    $ascResponse = $this->getJson(route('api.feed', ['sort' => 'asc']));
    $ascResponse->assertOk();
    $ascExternalIds = array_column($ascResponse->json('data') ?? [], 'external_id');
    expect($ascExternalIds)->toBe(['F2', 'F1']);
});

test('the feed api supports cursor pagination', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-03 12:00:00'));

    // Create 10 fire incidents
    for ($i = 1; $i <= 10; $i++) {
        FireIncident::factory()->create([
            'event_num' => "F{$i}",
            'is_active' => true,
            'dispatch_time' => Carbon::now()->subMinutes($i),
        ]);
    }

    // Get first page with small perPage via cursor (default is 50, so all will be returned)
    // Instead, we'll verify the structure has next_cursor
    $response = $this->getJson(route('api.feed'));
    $response->assertOk();

    $data = $response->json('data');
    $nextCursor = $response->json('next_cursor');

    // Since we have only 10 items and default perPage is 50, next_cursor should be null
    expect(count($data))->toBe(10)
        ->and($nextCursor)->toBeNull();
});

test('the feed api returns next_cursor when there are more results', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-03 12:00:00'));

    // Create 60 fire incidents (more than default perPage of 50)
    for ($i = 1; $i <= 60; $i++) {
        FireIncident::factory()->create([
            'event_num' => "F{$i}",
            'is_active' => true,
            'dispatch_time' => Carbon::now()->subMinutes($i),
        ]);
    }

    $response = $this->getJson(route('api.feed'));
    $response->assertOk();

    $data = $response->json('data');
    $nextCursor = $response->json('next_cursor');

    // Should have 50 items and a next_cursor
    expect(count($data))->toBe(50)
        ->and($nextCursor)->not->toBeNull()
        ->and($nextCursor)->toBeString();
});

test('the feed api respects per_page parameter', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-03 12:00:00'));

    for ($i = 1; $i <= 60; $i++) {
        FireIncident::factory()->create([
            'event_num' => "F{$i}",
            'is_active' => true,
            'dispatch_time' => Carbon::now()->subMinutes($i),
        ]);
    }

    $response = $this->getJson(route('api.feed', ['per_page' => 20]));
    $response->assertOk();

    $data = $response->json('data');
    $nextCursor = $response->json('next_cursor');

    expect(count($data))->toBe(20)
        ->and($nextCursor)->not->toBeNull();
});

test('the feed api fetches next page using cursor', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-03 12:00:00'));

    // Create 60 fire incidents
    for ($i = 1; $i <= 60; $i++) {
        FireIncident::factory()->create([
            'event_num' => "F{$i}",
            'is_active' => true,
            'dispatch_time' => Carbon::now()->subMinutes($i),
        ]);
    }

    // Get first page
    $page1 = $this->getJson(route('api.feed'));
    $page1Data = $page1->json('data');
    $cursor = $page1->json('next_cursor');

    // Get second page using cursor
    $page2 = $this->getJson(route('api.feed', ['cursor' => $cursor]));
    $page2Data = $page2->json('data');

    // Second page should have 10 items and no next cursor
    expect(count($page2Data))->toBe(10)
        ->and($page2->json('next_cursor'))->toBeNull();

    // Ensure no duplicates between pages
    $page1Ids = array_column($page1Data, 'id');
    $page2Ids = array_column($page2Data, 'id');
    $intersection = array_intersect($page1Ids, $page2Ids);
    expect($intersection)->toBeEmpty();
});

test('the feed api validates cursor parameter', function () {
    $response = $this->getJson(route('api.feed', ['cursor' => 'invalid-cursor']));
    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['cursor']);
});

test('the feed api rejects invalid status', function () {
    $response = $this->getJson(route('api.feed', ['status' => 'invalid']));
    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);
});

test('the feed api rejects invalid source', function () {
    $response = $this->getJson(route('api.feed', ['source' => 'invalid']));
    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['source']);
});

test('the feed api rejects invalid since', function () {
    $response = $this->getJson(route('api.feed', ['since' => '2d']));
    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['since']);
});

test('the feed api rejects invalid sort', function () {
    $response = $this->getJson(route('api.feed', ['sort' => 'invalid']));
    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['sort']);
});

test('the feed api rejects invalid per_page', function () {
    $response = $this->getJson(route('api.feed', ['per_page' => 0]));
    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['per_page']);
});

test('the feed api returns empty array when no alerts match', function () {
    $response = $this->getJson(route('api.feed'));
    $response->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('next_cursor', null);
});

test('the feed api combines multiple filters correctly', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-03 12:00:00'));

    $searchToken = 'FEED001COMBINATIONTOKEN';

    // Create active fire incident from 5 minutes ago
    FireIncident::factory()->create([
        'event_num' => 'F1',
        'is_active' => true,
        'dispatch_time' => Carbon::now()->subMinutes(5),
        'event_type' => "ALARM {$searchToken}",
    ]);

    // Create cleared fire incident from 5 minutes ago
    FireIncident::factory()->create([
        'event_num' => 'F2',
        'is_active' => false,
        'dispatch_time' => Carbon::now()->subMinutes(5),
        'event_type' => 'ALARM',
    ]);

    // Create active fire incident from 2 hours ago
    FireIncident::factory()->create([
        'event_num' => 'F3',
        'is_active' => true,
        'dispatch_time' => Carbon::now()->subHours(2),
        'event_type' => 'ALARM',
    ]);

    $response = $this->getJson(route('api.feed', [
        'status' => 'active',
        'source' => 'fire',
        'since' => '30m',
        'q' => $searchToken,
    ]));

    $response->assertOk();
    $data = $response->json('data');
    expect($data)->toHaveCount(1)
        ->and($data[0]['external_id'])->toBe('F1');
});

test('the feed api is rate limited', function () {
    // The route has throttle:120,1 middleware
    $route = route('api.feed');

    // First request should succeed
    $response1 = $this->getJson($route);
    $response1->assertOk();

    // The rate limit is high (120 per minute), so we just verify the middleware is applied
    // by checking the headers exist
    expect($response1->headers->has('X-RateLimit-Limit'))->toBeTrue();
});

test('the feed api returns empty array for short search query', function () {
    FireIncident::factory()->create([
        'event_num' => 'F1',
        'event_type' => 'ALARM',
    ]);

    $response = $this->getJson(route('api.feed', ['q' => 'a']));
    $response->assertOk()
        ->assertJsonCount(0, 'data');
});
