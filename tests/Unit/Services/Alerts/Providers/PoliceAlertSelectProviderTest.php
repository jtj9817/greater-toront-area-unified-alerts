<?php

use App\Models\PoliceCall;
use App\Services\Alerts\Providers\PoliceAlertSelectProvider;
use Carbon\CarbonImmutable;

test('police alert select provider maps unified columns', function () {
    $call = PoliceCall::factory()->create([
        'object_id' => 4242,
        'call_type_code' => 'THEFT',
        'call_type' => 'THEFT OVER',
        'division' => 'D51',
        'cross_streets' => 'Queen St - Spadina Ave',
        'latitude' => 43.6500,
        'longitude' => -79.3800,
        'occurrence_time' => CarbonImmutable::parse('2026-02-02 13:00:00'),
        'is_active' => false,
    ]);

    $row = (new PoliceAlertSelectProvider())->select()->first();

    expect($row)->not->toBeNull();
    expect($row->id)->toBe('police:4242');
    expect($row->source)->toBe('police');
    expect((string) $row->external_id)->toBe('4242');
    expect((int) $row->is_active)->toBe(0);
    expect((string) $row->timestamp)->toBe($call->occurrence_time->format('Y-m-d H:i:s'));
    expect($row->title)->toBe('THEFT OVER');
    expect($row->location_name)->toBe('Queen St - Spadina Ave');
    expect((float) $row->lat)->toBe(43.65);
    expect((float) $row->lng)->toBe(-79.38);

    $decodeMeta = fn (mixed $value): array => is_array($value)
        ? $value
        : (is_string($value) && $value !== ''
            ? json_decode($value, true, 512, JSON_THROW_ON_ERROR)
            : []);

    $meta = $decodeMeta($row->meta);

    expect($meta['division'])->toBe('D51');
    expect($meta['call_type_code'])->toBe('THEFT');
    expect($meta['object_id'])->toBe(4242);
});
