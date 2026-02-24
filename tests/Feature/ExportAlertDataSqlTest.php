<?php

use App\Models\FireIncident;
use App\Models\GoTransitAlert;
use App\Models\PoliceCall;
use App\Models\TransitAlert;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeSqlExportPath(string $extension = '.sql'): string
{
    return sys_get_temp_dir().'/alert-export-'.bin2hex(random_bytes(6)).$extension;
}

function readTextFile(string $path): string
{
    $contents = file_get_contents($path);
    expect($contents)->not->toBeFalse();

    return (string) $contents;
}

test('db export sql exports default tables with postgres upsert statements', function () {
    FireIncident::factory()->create(['event_num' => 'F26010001']);
    PoliceCall::factory()->create(['object_id' => 700001]);
    TransitAlert::factory()->create(['external_id' => 'api:phase1:one']);
    GoTransitAlert::factory()->create(['external_id' => 'notif:AB:TDELAY:PHASEONE']);

    $outputPath = makeSqlExportPath();

    try {
        $this->artisan('db:export-sql', [
            '--output' => $outputPath,
            '--chunk' => 1,
        ])->assertExitCode(0);

        $contents = readTextFile($outputPath);

        expect($contents)->toContain('INSERT INTO "fire_incidents"');
        expect($contents)->toContain('INSERT INTO "police_calls"');
        expect($contents)->toContain('INSERT INTO "transit_alerts"');
        expect($contents)->toContain('INSERT INTO "go_transit_alerts"');
        expect($contents)->toContain('ON CONFLICT (id) DO NOTHING;');
        expect($contents)->not->toContain('`');
    } finally {
        @unlink($outputPath);
    }
});

test('db export sql supports table filtering chunking and no header', function () {
    FireIncident::factory()->create(['event_num' => 'F26020001']);
    FireIncident::factory()->create(['event_num' => 'F26020002']);
    FireIncident::factory()->create(['event_num' => 'F26020003']);
    TransitAlert::factory()->create(['external_id' => 'api:phase1:filtered-out']);

    $outputPath = makeSqlExportPath();

    try {
        $this->artisan('db:export-sql', [
            '--output' => $outputPath,
            '--tables' => 'fire_incidents',
            '--chunk' => 2,
            '--no-header' => true,
        ])->assertExitCode(0);

        $contents = readTextFile($outputPath);

        expect(substr_count($contents, 'INSERT INTO "fire_incidents"'))->toBe(2);
        expect($contents)->not->toContain('INSERT INTO "transit_alerts"');
        expect($contents)->not->toContain('SET client_encoding');
        expect($contents)->not->toContain('SET TIME ZONE');
    } finally {
        @unlink($outputPath);
    }
});

test('db export sql supports gzip output', function () {
    FireIncident::factory()->create(['event_num' => 'F26030001']);

    $outputPath = makeSqlExportPath();

    try {
        $this->artisan('db:export-sql', [
            '--output' => $outputPath,
            '--compress' => true,
        ])->assertExitCode(0);

        $compressedPath = $outputPath.'.gz';

        expect(file_exists($compressedPath))->toBeTrue();

        $compressedContents = file_get_contents($compressedPath);
        expect($compressedContents)->not->toBeFalse();

        $decoded = gzdecode((string) $compressedContents);
        expect($decoded)->not->toBeFalse();
        expect((string) $decoded)->toContain('INSERT INTO "fire_incidents"');
    } finally {
        @unlink($outputPath);
        @unlink($outputPath.'.gz');
    }
});

test('db export sql emits deterministic header by default and writes to default output path', function () {
    FireIncident::factory()->create(['event_num' => 'F26040001']);

    $defaultPath = storage_path('app/alert-export.sql');
    @unlink($defaultPath);

    try {
        $this->artisan('db:export-sql')
            ->assertExitCode(0);

        expect(file_exists($defaultPath))->toBeTrue();

        $contents = readTextFile($defaultPath);

        expect($contents)->toContain("SET client_encoding = 'UTF8';");
        expect($contents)->toContain("SET TIME ZONE 'UTC';");
    } finally {
        @unlink($defaultPath);
    }
});

test('db export sql preserves null values and escapes single quotes correctly', function () {
    FireIncident::factory()->create([
        'event_num' => 'F26050001',
        'event_type' => "O'HARA",
        'prime_street' => null,
        'cross_streets' => "Queen's Quay & King's",
        'dispatch_time' => CarbonImmutable::parse('2026-02-20 12:34:56', 'UTC'),
        'units_dispatched' => "P1,'P2'",
    ]);

    $outputPath = makeSqlExportPath();

    try {
        $this->artisan('db:export-sql', [
            '--output' => $outputPath,
            '--tables' => 'fire_incidents',
            '--no-header' => true,
        ])->assertExitCode(0);

        $contents = readTextFile($outputPath);

        expect($contents)->toContain("'O''HARA'");
        expect($contents)->toContain("'Queen''s Quay & King''s'");
        expect($contents)->toContain("'P1,''P2'''");
        expect($contents)->toContain('NULL');
        expect($contents)->not->toContain("'NULL'");
    } finally {
        @unlink($outputPath);
    }
});
