<?php

use App\Enums\IncidentUpdateType;
use App\Models\FireIncident;
use App\Models\IncidentUpdate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('pgsql feed keeps fire intel_summary as array and intel_last_updated null when no updates exist', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL only.');
    }

    FireIncident::factory()->create([
        'event_num' => 'F-PGSQL-NO-UPDATES',
        'event_type' => 'ALARM FIRE',
        'dispatch_time' => CarbonImmutable::parse('2026-02-27 11:55:00', 'UTC'),
        'is_active' => true,
    ]);

    $response = $this->getJson(route('api.feed', ['source' => 'fire']));
    $response->assertOk();

    $data = $response->json('data');
    expect($data)->toHaveCount(1);
    expect($data[0]['meta']['intel_summary'])->toBeArray()->toBeEmpty();
    expect($data[0]['meta']['intel_last_updated'])->toBeNull();
});

test('pgsql feed emits iso-8601 offset intel_last_updated when incident updates exist', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL only.');
    }

    FireIncident::factory()->create([
        'event_num' => 'F-PGSQL-WITH-UPDATES',
        'event_type' => 'ALARM FIRE',
        'dispatch_time' => CarbonImmutable::parse('2026-02-27 11:50:00', 'UTC'),
        'is_active' => true,
    ]);

    IncidentUpdate::factory()->create([
        'event_num' => 'F-PGSQL-WITH-UPDATES',
        'update_type' => IncidentUpdateType::RESOURCE_STATUS,
        'content' => 'Units dispatched',
        'created_at' => CarbonImmutable::parse('2026-02-27 11:52:00', 'UTC'),
        'updated_at' => CarbonImmutable::parse('2026-02-27 11:52:00', 'UTC'),
    ]);

    $response = $this->getJson(route('api.feed', ['source' => 'fire']));
    $response->assertOk();

    $data = $response->json('data');
    expect($data)->toHaveCount(1);
    expect($data[0]['meta']['intel_summary'])->toBeArray()->not->toBeEmpty();
    expect($data[0]['meta']['intel_last_updated'])->toBeString();
    expect($data[0]['meta']['intel_last_updated'])
        ->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})$/');
});

test('pgsql q filtering reduces result set for fire provider without query exceptions', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL only.');
    }

    FireIncident::factory()->create([
        'event_num' => 'F-PGSQL-MATCH',
        'event_type' => 'ALARM FEED010QFILTERTOKEN',
        'prime_street' => 'Bay Street',
        'cross_streets' => 'King Street',
        'dispatch_time' => CarbonImmutable::parse('2026-02-27 11:58:00', 'UTC'),
        'is_active' => true,
    ]);

    FireIncident::factory()->create([
        'event_num' => 'F-PGSQL-NOMATCH',
        'event_type' => 'MEDICAL ASSIST',
        'prime_street' => 'Bloor Street',
        'cross_streets' => 'Yonge Street',
        'dispatch_time' => CarbonImmutable::parse('2026-02-27 11:57:00', 'UTC'),
        'is_active' => true,
    ]);

    $withoutQuery = $this->getJson(route('api.feed', ['source' => 'fire']));
    $withoutQuery->assertOk();
    $withoutData = $withoutQuery->json('data');
    expect($withoutData)->toHaveCount(2);

    $withQuery = $this->getJson(route('api.feed', [
        'source' => 'fire',
        'q' => 'FEED010QFILTERTOKEN',
    ]));
    $withQuery->assertOk();

    $withData = $withQuery->json('data');
    expect($withData)->toHaveCount(1);
    expect($withData[0]['external_id'])->toBe('F-PGSQL-MATCH');
});
