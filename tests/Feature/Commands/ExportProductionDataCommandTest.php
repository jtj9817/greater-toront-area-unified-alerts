<?php

use App\Console\Commands\ExportProductionData;
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

function makeSeederOutputDirectory(): string
{
    $directory = sys_get_temp_dir().'/production-seed-'.bin2hex(random_bytes(6));

    if (! mkdir($directory, 0755, true) && ! is_dir($directory)) {
        throw new RuntimeException("Unable to create temporary seeder test directory: {$directory}");
    }

    return $directory;
}

function deleteDirectoryRecursively(string $directory): void
{
    if (! is_dir($directory)) {
        return;
    }

    $paths = glob($directory.'/*');
    if (is_array($paths)) {
        foreach ($paths as $path) {
            if (is_dir($path)) {
                deleteDirectoryRecursively($path);
            } else {
                @unlink($path);
            }
        }
    }

    @rmdir($directory);
}

function assertPhpFileLintPasses(string $path): void
{
    $output = [];
    $exitCode = 0;

    exec('php -l '.escapeshellarg($path).' 2>&1', $output, $exitCode);

    expect($exitCode)
        ->toBe(0, "Expected '{$path}' to be valid PHP. Lint output: ".implode("\n", $output));
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

test('it splits export output into linked part seeders when output exceeds configured max bytes', function () {
    FireIncident::factory()->count(50)->create([
        'units_dispatched' => str_repeat('P100, ', 30),
    ]);

    $directory = makeSeederOutputDirectory();
    $outputPath = $directory.'/ProductionDataSeeder.php';

    try {
        $this->artisan('db:export-to-seeder', [
            '--path' => $outputPath,
            '--chunk' => 5,
            '--max-bytes' => 3000,
        ])->assertExitCode(0);

        expect(file_exists($outputPath))->toBeTrue();

        $partFiles = glob($directory.'/ProductionDataSeeder_Part*.php');
        expect($partFiles)->toBeArray();
        expect($partFiles)->not->toBeFalse();
        expect(count($partFiles))->toBeGreaterThan(1);

        $mainContents = file_get_contents($outputPath);
        expect($mainContents)->not->toBeFalse();
        expect($mainContents)->toContain('$this->call([');
        expect($mainContents)->toContain('ProductionDataSeeder_Part1::class');
        expect($mainContents)->toContain('ProductionDataSeeder_Part2::class');

        foreach ($partFiles as $partFile) {
            $partContents = file_get_contents($partFile);
            expect($partContents)->not->toBeFalse();
            expect($partContents)->toContain('insertOrIgnore');
            assertPhpFileLintPasses($partFile);
        }

        assertPhpFileLintPasses($outputPath);
    } finally {
        deleteDirectoryRecursively($directory);
    }
});

test('it warns and falls back for invalid chunk and max-bytes values', function () {
    FireIncident::factory()->count(2)->create();

    $outputPath = makeSeederOutputPath();

    try {
        $this->artisan('db:export-to-seeder', [
            '--path' => $outputPath,
            '--chunk' => 0,
            '--max-bytes' => 0,
        ])->assertExitCode(0)
            ->expectsOutputToContain('Invalid --chunk value provided. Falling back to 500.')
            ->expectsOutputToContain('Invalid --max-bytes value provided. Falling back to 10485760.');
    } finally {
        @unlink($outputPath);
    }
});

test('it fails when output directory cannot be created', function () {
    $directory = makeSeederOutputDirectory();
    $blockingFile = $directory.'/blocked-parent';
    file_put_contents($blockingFile, 'x');
    $outputPath = $blockingFile.'/ProductionDataSeeder.php';

    try {
        expect(fn () => $this->artisan('db:export-to-seeder', [
            '--path' => $outputPath,
        ]))->toThrow(\ErrorException::class, 'mkdir(): File exists');
    } finally {
        @unlink($blockingFile);
        deleteDirectoryRecursively($directory);
    }
});

test('it fails cleanly when split rename step cannot create part files', function () {
    if (DIRECTORY_SEPARATOR === '\\') {
        $this->markTestSkipped('Directory permission assertions are not reliable on Windows.');
    }
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $this->markTestSkipped('Directory permission assertions are not reliable when running as root.');
    }

    FireIncident::factory()->count(40)->create([
        'units_dispatched' => str_repeat('P100, ', 30),
    ]);

    $directory = makeSeederOutputDirectory();
    $outputPath = $directory.'/ProductionDataSeeder.php';

    try {
        file_put_contents($outputPath, '');
        chmod($directory, 0555);
        clearstatcache(true, $directory);

        // If the filesystem ignores permissions (or chmod failed), the command may still succeed.
        $probePath = $directory.'/.writability-probe';
        $probeWrite = @file_put_contents($probePath, 'x');
        if ($probeWrite !== false) {
            @unlink($probePath);
            $this->markTestSkipped('Unable to make output directory non-writable in this environment.');
        }

        $this->artisan('db:export-to-seeder', [
            '--path' => $outputPath,
            '--chunk' => 5,
            '--max-bytes' => 3000,
        ])->assertExitCode(1)
            ->expectsOutputToContain('Export failed');
    } finally {
        @chmod($directory, 0755);
        deleteDirectoryRecursively($directory);
    }
});

test('it throws guard errors when writeBlock and closeSeederFile are called without an active file', function () {
    $command = new ExportProductionData;
    $reflection = new \ReflectionClass($command);

    $writeBlock = $reflection->getMethod('writeBlock');
    $writeBlock->setAccessible(true);

    $closeSeederFile = $reflection->getMethod('closeSeederFile');
    $closeSeederFile->setAccessible(true);

    expect(fn () => $writeBlock->invoke($command, 'test'))
        ->toThrow(\RuntimeException::class, 'Cannot write export block without an active seeder file.');

    expect(fn () => $closeSeederFile->invoke($command))
        ->toThrow(\RuntimeException::class, 'No active seeder file is open for writing.');
});
