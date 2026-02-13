<?php

use App\Enums\IncidentUpdateType;
use App\Models\FireIncident;
use App\Models\IncidentUpdate;
use App\Models\User;
use App\Services\SceneIntel\SceneIntelRepository;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->repository = app(SceneIntelRepository::class);
});

test('it returns latest updates for an incident with limit', function () {
    $incident = FireIncident::factory()->create();
    $otherIncident = FireIncident::factory()->create();

    IncidentUpdate::factory()->create([
        'event_num' => $otherIncident->event_num,
        'created_at' => Carbon::parse('2026-02-13 09:58:00'),
    ]);

    IncidentUpdate::factory()->create([
        'event_num' => $incident->event_num,
        'created_at' => Carbon::parse('2026-02-13 10:00:00'),
    ]);

    IncidentUpdate::factory()->create([
        'event_num' => $incident->event_num,
        'created_at' => Carbon::parse('2026-02-13 10:01:00'),
    ]);

    IncidentUpdate::factory()->create([
        'event_num' => $incident->event_num,
        'created_at' => Carbon::parse('2026-02-13 10:02:00'),
    ]);

    $updates = $this->repository->getLatestForIncident($incident->event_num, 2);

    expect($updates)->toHaveCount(2);
    expect($updates->pluck('event_num')->unique()->all())->toBe([$incident->event_num]);
    expect($updates->pluck('created_at')->map(fn ($time) => $time->toDateTimeString())->all())
        ->toBe([
            '2026-02-13 10:02:00',
            '2026-02-13 10:01:00',
        ]);
});

test('it uses id as a deterministic tiebreaker for latest and summary queries', function () {
    $incident = FireIncident::factory()->create();
    $createdAt = Carbon::parse('2026-02-13 10:00:00');

    $oldestId = IncidentUpdate::factory()->create([
        'event_num' => $incident->event_num,
        'content' => 'First same-second update',
        'created_at' => $createdAt,
    ])->id;

    $middleId = IncidentUpdate::factory()->create([
        'event_num' => $incident->event_num,
        'content' => 'Second same-second update',
        'created_at' => $createdAt,
    ])->id;

    $newestId = IncidentUpdate::factory()->create([
        'event_num' => $incident->event_num,
        'content' => 'Third same-second update',
        'created_at' => $createdAt,
    ])->id;

    $latest = $this->repository->getLatestForIncident($incident->event_num, 2);
    $summary = $this->repository->getSummaryForIncident($incident->event_num, 2);

    expect($latest->pluck('id')->all())->toBe([$newestId, $middleId]);
    expect($summary)->toHaveCount(2);
    expect(array_column($summary, 'id'))->toBe([$newestId, $middleId]);
    expect($oldestId)->toBeLessThan($middleId)->toBeLessThan($newestId);
});

test('it returns timeline in chronological order', function () {
    $incident = FireIncident::factory()->create();

    IncidentUpdate::factory()->create([
        'event_num' => $incident->event_num,
        'created_at' => Carbon::parse('2026-02-13 10:02:00'),
    ]);

    IncidentUpdate::factory()->create([
        'event_num' => $incident->event_num,
        'created_at' => Carbon::parse('2026-02-13 10:00:00'),
    ]);

    IncidentUpdate::factory()->create([
        'event_num' => $incident->event_num,
        'created_at' => Carbon::parse('2026-02-13 10:01:00'),
    ]);

    $timeline = $this->repository->getTimeline($incident->event_num);

    expect($timeline->pluck('created_at')->map(fn ($time) => $time->toDateTimeString())->all())
        ->toBe([
            '2026-02-13 10:00:00',
            '2026-02-13 10:01:00',
            '2026-02-13 10:02:00',
        ]);
});

test('it uses id as a deterministic tiebreaker for timeline queries', function () {
    $incident = FireIncident::factory()->create();
    $createdAt = Carbon::parse('2026-02-13 10:00:00');

    $first = IncidentUpdate::factory()->create([
        'event_num' => $incident->event_num,
        'created_at' => $createdAt,
    ]);

    $second = IncidentUpdate::factory()->create([
        'event_num' => $incident->event_num,
        'created_at' => $createdAt,
    ]);

    $third = IncidentUpdate::factory()->create([
        'event_num' => $incident->event_num,
        'created_at' => $createdAt,
    ]);

    $timeline = $this->repository->getTimeline($incident->event_num);

    expect($timeline->pluck('id')->all())->toBe([$first->id, $second->id, $third->id]);
});

test('it returns intel summary payload for an incident', function () {
    $incident = FireIncident::factory()->create();

    $latest = IncidentUpdate::factory()->create([
        'event_num' => $incident->event_num,
        'update_type' => IncidentUpdateType::ALARM_CHANGE,
        'content' => 'Escalated to 2-Alarm',
        'metadata' => ['newLevel' => 2],
        'created_at' => Carbon::parse('2026-02-13 10:02:00'),
    ]);

    IncidentUpdate::factory()->create([
        'event_num' => $incident->event_num,
        'update_type' => IncidentUpdateType::RESOURCE_STATUS,
        'content' => 'Pumper P144 dispatched',
        'metadata' => ['unitCode' => 'P144'],
        'created_at' => Carbon::parse('2026-02-13 10:01:00'),
    ]);

    $summary = $this->repository->getSummaryForIncident($incident->event_num, 1);

    expect($summary)->toHaveCount(1);
    expect($summary[0]['id'])->toBe($latest->id);
    expect($summary[0]['type'])->toBe('alarm_change');
    expect($summary[0]['content'])->toBe('Escalated to 2-Alarm');
    expect($summary[0]['metadata'])->toBe(['newLevel' => 2]);
    expect($summary[0]['timestamp'])->toBe('2026-02-13T10:02:00+00:00');
});

test('it adds a manual entry for an incident', function () {
    $incident = FireIncident::factory()->create();
    $user = User::factory()->create();

    $entry = $this->repository->addManualEntry(
        eventNum: $incident->event_num,
        content: 'Primary search complete',
        userId: $user->id,
        metadata: ['milestoneType' => 'primary_search_complete'],
    );

    expect($entry->event_num)->toBe($incident->event_num);
    expect($entry->update_type)->toBe(IncidentUpdateType::MANUAL_NOTE);
    expect($entry->content)->toBe('Primary search complete');
    expect($entry->metadata)->toBe(['milestoneType' => 'primary_search_complete']);
    expect($entry->source)->toBe('manual');
    expect($entry->created_by)->toBe($user->id);
});
