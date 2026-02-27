<?php

use App\Models\FireIncident;
use App\Models\GoTransitAlert;
use App\Models\PoliceCall;
use App\Models\TransitAlert;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
        expect($contents)->toContain("pg_get_serial_sequence('fire_incidents', 'id')");
        expect($contents)->toContain("pg_get_serial_sequence('police_calls', 'id')");
        expect($contents)->toContain("pg_get_serial_sequence('transit_alerts', 'id')");
        expect($contents)->toContain("pg_get_serial_sequence('go_transit_alerts', 'id')");
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
        'alarm_level' => 3,
        'is_active' => false,
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
        expect($contents)->toContain(', FALSE,');
        expect($contents)->not->toContain(', 0,');
        expect($contents)->toContain('NULL');
        expect($contents)->not->toContain("'NULL'");
    } finally {
        @unlink($outputPath);
    }
});

test('db export sql rejects unsupported tables', function () {
    $outputPath = makeSqlExportPath();

    try {
        $this->artisan('db:export-sql', [
            '--output' => $outputPath,
            '--tables' => 'fire_incidents,not_a_table',
        ])
            ->expectsOutputToContain('Export failed: Unsupported table(s): not_a_table.')
            ->assertExitCode(1);
    } finally {
        @unlink($outputPath);
    }
});

test('db export sql rejects empty normalized tables option', function () {
    $outputPath = makeSqlExportPath();

    try {
        $this->artisan('db:export-sql', [
            '--output' => $outputPath,
            '--tables' => ' , , ',
        ])
            ->expectsOutputToContain('Export failed: No tables were provided for export.')
            ->assertExitCode(1);
    } finally {
        @unlink($outputPath);
    }
});

test('db export sql warns and falls back when chunk size is invalid', function () {
    FireIncident::factory()->create(['event_num' => 'F26060001']);

    $outputPath = makeSqlExportPath();

    try {
        $this->artisan('db:export-sql', [
            '--output' => $outputPath,
            '--tables' => 'fire_incidents',
            '--chunk' => 0,
            '--no-header' => true,
        ])
            ->expectsOutputToContain('Invalid --chunk value provided. Falling back to 500.')
            ->assertExitCode(0);
    } finally {
        @unlink($outputPath);
    }
});

test('db export sql respects explicit gzip extension without appending another suffix', function () {
    FireIncident::factory()->create(['event_num' => 'F26070001']);

    $outputPath = makeSqlExportPath('.sql.gz');

    try {
        $this->artisan('db:export-sql', [
            '--output' => $outputPath,
            '--compress' => true,
        ])->assertExitCode(0);

        expect(file_exists($outputPath))->toBeTrue();
        expect(file_exists($outputPath.'.gz'))->toBeFalse();
    } finally {
        @unlink($outputPath);
    }
});

test('db export sql fails when output directory cannot be created', function () {
    $blockingPath = sys_get_temp_dir().'/alert-export-blocking-'.bin2hex(random_bytes(6));
    file_put_contents($blockingPath, 'blocking-file');

    $outputPath = $blockingPath.'/nested/export.sql';

    try {
        $this->artisan('db:export-sql', [
            '--output' => $outputPath,
            '--tables' => 'fire_incidents',
        ])
            ->expectsOutputToContain('Export failed: Unable to create output directory:')
            ->assertExitCode(1);
    } finally {
        @unlink($blockingPath);
    }
});

test('db export sql fails when output path points to an existing directory in plain mode', function () {
    $outputPath = sys_get_temp_dir().'/alert-export-dir-'.bin2hex(random_bytes(6));
    mkdir($outputPath, 0755, true);

    try {
        $this->artisan('db:export-sql', [
            '--output' => $outputPath,
            '--tables' => 'fire_incidents',
        ])
            ->expectsOutputToContain('Export failed: Unable to open output file for writing:')
            ->assertExitCode(1);
    } finally {
        @rmdir($outputPath);
    }
});

test('db export sql fails when output path points to an existing directory in gzip mode', function () {
    $outputPath = sys_get_temp_dir().'/alert-export-dir-'.bin2hex(random_bytes(6)).'.gz';
    mkdir($outputPath, 0755, true);

    try {
        $this->artisan('db:export-sql', [
            '--output' => $outputPath,
            '--tables' => 'fire_incidents',
            '--compress' => true,
        ])
            ->expectsOutputToContain('Export failed: Unable to open compressed output file:')
            ->assertExitCode(1);
    } finally {
        @rmdir($outputPath);
    }
});

test('db export sql fails when target table is missing from schema', function () {
    Schema::disableForeignKeyConstraints();
    Schema::drop('fire_incidents');
    Schema::enableForeignKeyConstraints();

    $outputPath = makeSqlExportPath();

    try {
        $this->artisan('db:export-sql', [
            '--output' => $outputPath,
            '--tables' => 'fire_incidents',
        ])
            ->expectsOutputToContain('Export failed: Table does not exist: fire_incidents')
            ->assertExitCode(1);
    } finally {
        @unlink($outputPath);
    }
});

test('db export sql fails when table does not expose required id column', function () {
    Schema::partialMock()
        ->shouldReceive('hasTable')
        ->once()
        ->with('fire_incidents')
        ->andReturn(true);

    Schema::shouldReceive('getColumnListing')
        ->once()
        ->with('fire_incidents')
        ->andReturn(['event_num', 'event_type']);

    $outputPath = makeSqlExportPath();

    try {
        $this->artisan('db:export-sql', [
            '--output' => $outputPath,
            '--tables' => 'fire_incidents',
        ])
            ->expectsOutputToContain('Export failed: Table fire_incidents is missing required primary key column: id')
            ->assertExitCode(1);
    } finally {
        @unlink($outputPath);
    }
});

test('db export sql normalizes boolean literals for numeric and string variants', function () {
    $now = now();
    $isMySqlFamily = in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);

    $rows = [
        ['event_num' => 'BOOL_ZERO', 'is_active' => 0],
        ['event_num' => 'BOOL_ONE', 'is_active' => 1],
    ];

    if (! $isMySqlFamily) {
        $rows = [
            ...$rows,
            ['event_num' => 'BOOL_NO', 'is_active' => 'no'],
            ['event_num' => 'BOOL_OFF', 'is_active' => 'off'],
            ['event_num' => 'BOOL_YES', 'is_active' => 'yes'],
            ['event_num' => 'BOOL_ON', 'is_active' => 'on'],
            ['event_num' => 'BOOL_UNKNOWN', 'is_active' => 'maybe'],
        ];
    }

    foreach ($rows as $index => $row) {
        DB::table('fire_incidents')->insert([
            'event_num' => $row['event_num'],
            'event_type' => 'Alarm',
            'prime_street' => 'Queen St W',
            'cross_streets' => null,
            'dispatch_time' => CarbonImmutable::parse('2026-02-20 12:00:00', 'UTC')->addMinutes($index),
            'alarm_level' => 1,
            'beat' => null,
            'units_dispatched' => null,
            'is_active' => $row['is_active'],
            'feed_updated_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    $outputPath = makeSqlExportPath();

    try {
        $this->artisan('db:export-sql', [
            '--output' => $outputPath,
            '--tables' => 'fire_incidents',
            '--no-header' => true,
        ])->assertExitCode(0);

        $contents = readTextFile($outputPath);
        $valueLines = array_values(array_filter(
            explode("\n", $contents),
            static fn (string $line): bool => str_contains($line, "'BOOL_")
        ));

        $lineFor = static function (string $eventNum) use ($valueLines): string {
            foreach ($valueLines as $line) {
                if (str_contains($line, "'{$eventNum}'")) {
                    return $line;
                }
            }

            return '';
        };

        expect($lineFor('BOOL_ZERO'))->toContain('FALSE');
        expect($lineFor('BOOL_ONE'))->toContain('TRUE');

        if (! $isMySqlFamily) {
            expect($lineFor('BOOL_NO'))->toContain('FALSE');
            expect($lineFor('BOOL_OFF'))->toContain('FALSE');
            expect($lineFor('BOOL_YES'))->toContain('TRUE');
            expect($lineFor('BOOL_ON'))->toContain('TRUE');
            expect($lineFor('BOOL_UNKNOWN'))->toContain('TRUE');
        }
    } finally {
        @unlink($outputPath);
    }
});
