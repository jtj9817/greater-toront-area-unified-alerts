<?php

use App\Models\FireIncident;
use App\Models\GoTransitAlert;
use App\Models\PoliceCall;
use App\Models\TransitAlert;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeSeederOutputPath(): string
{
    $path = tempnam(sys_get_temp_dir(), 'production-seed-');

    if ($path === false) {
        throw new RuntimeException('Unable to create temporary file path for seeder export test.');
    }

    return $path;
}

test('it exports a single model dataset using insertOrIgnore and preserves timestamps', function () {
    $createdAt = CarbonImmutable::parse('2026-02-06 12:34:56', 'UTC');
    $updatedAt = CarbonImmutable::parse('2026-02-06 12:40:00', 'UTC');

    FireIncident::factory()->create([
        'event_num' => 'F26099999',
        'created_at' => $createdAt,
        'updated_at' => $updatedAt,
    ]);

    $outputPath = makeSeederOutputPath();

    try {
        $this->artisan('db:export-to-seeder', [
            '--path' => $outputPath,
            '--chunk' => 1,
        ])->assertExitCode(0);

        expect(file_exists($outputPath))->toBeTrue();

        $contents = file_get_contents($outputPath);

        expect($contents)->not->toBeFalse();
        expect($contents)->toContain("DB::table('fire_incidents')->insertOrIgnore(");
        expect($contents)->toContain("'event_num' => 'F26099999'");
        expect($contents)->toContain("'created_at' => '2026-02-06 12:34:56'");
        expect($contents)->toContain("'updated_at' => '2026-02-06 12:40:00'");
    } finally {
        @unlink($outputPath);
    }
});

test('it exports all alert models into the generated seeder', function () {
    FireIncident::factory()->create([
        'event_num' => 'F26012345',
    ]);

    PoliceCall::factory()->create([
        'object_id' => 888888,
    ]);

    TransitAlert::factory()->create([
        'external_id' => 'api:phase1-all-models',
    ]);

    GoTransitAlert::factory()->create([
        'external_id' => 'notif:PHASE1:ALLMODELS',
    ]);

    $outputPath = makeSeederOutputPath();

    try {
        $this->artisan('db:export-to-seeder', [
            '--path' => $outputPath,
            '--chunk' => 2,
        ])->assertExitCode(0);

        $contents = file_get_contents($outputPath);

        expect($contents)->not->toBeFalse();
        expect($contents)->toContain("DB::table('fire_incidents')->insertOrIgnore(");
        expect($contents)->toContain("DB::table('police_calls')->insertOrIgnore(");
        expect($contents)->toContain("DB::table('transit_alerts')->insertOrIgnore(");
        expect($contents)->toContain("DB::table('go_transit_alerts')->insertOrIgnore(");

        expect($contents)->toContain("'event_num' => 'F26012345'");
        expect($contents)->toContain("'object_id' => 888888");
        expect($contents)->toContain("'external_id' => 'api:phase1-all-models'");
        expect($contents)->toContain("'external_id' => 'notif:PHASE1:ALLMODELS'");
    } finally {
        @unlink($outputPath);
    }
});
