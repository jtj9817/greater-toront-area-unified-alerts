<?php

/**
 * Manual Test: Production Data Migration - Phase 4 Final Quality Gate
 * Generated: 2026-02-09
 * Purpose: End-to-end verification for export, verification, restore fidelity,
 * idempotency, linting, and command-level test quality checks.
 */

require __DIR__.'/../../vendor/autoload.php';

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';

$app = require_once __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

if (app()->environment('production')) {
    exit("Error: Cannot run manual tests in production!\n");
}

if (function_exists('posix_geteuid') && posix_geteuid() === 0 && getenv('ALLOW_ROOT_MANUAL_TESTS') !== '1') {
    fwrite(STDERR, "Error: Do not run manual tests as root. Use `php tests/manual/...` as your normal user.\n");
    fwrite(STDERR, "If you really need root, re-run with ALLOW_ROOT_MANUAL_TESTS=1 (not recommended).\n");
    exit(1);
}

umask(002);

use App\Models\FireIncident;
use App\Models\GoTransitAlert;
use App\Models\PoliceCall;
use App\Models\TransitAlert;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

$testRunId = 'production_data_migration_phase_4_'.CarbonImmutable::now()->format('Y_m_d_His');
$logFileRelative = "storage/logs/manual_tests/{$testRunId}.log";
$logFile = storage_path("logs/manual_tests/{$testRunId}.log");
$workingDir = storage_path("app/private/manual_tests/{$testRunId}");
$repoRoot = realpath(__DIR__.'/../..');
$primaryDbPath = $workingDir.'/primary.sqlite';
$secondaryDbPath = $workingDir.'/secondary.sqlite';

$logDir = dirname($logFile);
if (! is_dir($logDir)) {
    mkdir($logDir, 0775, true);
}

if (! file_exists($logFile)) {
    touch($logFile);
    chmod($logFile, 0664);
}

config(['logging.channels.manual_test' => [
    'driver' => 'single',
    'path' => $logFile,
    'level' => 'debug',
]]);

function logInfo(string $msg, array $ctx = []): void
{
    Log::channel('manual_test')->info($msg, $ctx);
    echo "[INFO] {$msg}\n";
}

function logWarn(string $msg, array $ctx = []): void
{
    Log::channel('manual_test')->warning($msg, $ctx);
    echo "[WARN] {$msg}\n";
}

function logError(string $msg, array $ctx = []): void
{
    Log::channel('manual_test')->error($msg, $ctx);
    echo "[ERROR] {$msg}\n";
}

function assertTrueManual(bool $condition, string $label, array $ctx = []): void
{
    if (! $condition) {
        logError("Assertion failed: {$label}", $ctx);
        throw new RuntimeException("Assertion failed: {$label}");
    }

    logInfo("Assertion passed: {$label}");
}

function assertSameManual(mixed $actual, mixed $expected, string $label): void
{
    if ($actual !== $expected) {
        logError("Assertion failed: {$label}", [
            'expected' => $expected,
            'actual' => $actual,
        ]);
        throw new RuntimeException("Assertion failed: {$label}");
    }

    logInfo("Assertion passed: {$label}");
}

function deleteDirectoryRecursivelyManual(string $directory): void
{
    if (! is_dir($directory)) {
        return;
    }

    $entries = glob($directory.'/*');
    if (is_array($entries)) {
        foreach ($entries as $entry) {
            if (is_dir($entry)) {
                deleteDirectoryRecursivelyManual($entry);
            } else {
                @unlink($entry);
            }
        }
    }

    @rmdir($directory);
}

/**
 * @return array{output: string, exit_code: int}
 */
function runCommandManual(string $command, string $cwd): array
{
    $output = [];
    $exitCode = 0;

    exec('cd '.escapeshellarg($cwd).' && '.$command.' 2>&1', $output, $exitCode);

    return [
        'output' => implode("\n", $output),
        'exit_code' => $exitCode,
    ];
}

function configureSqliteConnectionManual(string $name, string $databasePath): void
{
    config([
        "database.connections.{$name}" => [
            'driver' => 'sqlite',
            'database' => $databasePath,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    ]);

    DB::purge($name);
}

function setDefaultConnectionManual(string $connection): void
{
    config(['database.default' => $connection]);
    DB::setDefaultConnection($connection);
    DB::purge($connection);
    DB::reconnect($connection);
}

function normalizeRowManual(array $row): array
{
    $normalized = [];

    foreach ($row as $key => $value) {
        if ($value === null) {
            $normalized[$key] = null;

            continue;
        }

        if ($value instanceof DateTimeInterface) {
            $normalized[$key] = $value->format('Y-m-d H:i:s');

            continue;
        }

        if (is_bool($value)) {
            $normalized[$key] = $value ? '1' : '0';

            continue;
        }

        $normalized[$key] = (string) $value;
    }

    ksort($normalized);

    return $normalized;
}

/**
 * @return array<int, array<string, string|null>>
 */
function snapshotTableManual(string $connection, string $table): array
{
    return DB::connection($connection)
        ->table($table)
        ->orderBy('id')
        ->get()
        ->map(fn ($row): array => normalizeRowManual((array) $row))
        ->all();
}

/**
 * @return array<string, array<int, array<string, string|null>>>
 */
function snapshotDatasetManual(string $connection): array
{
    $tables = [
        'fire_incidents',
        'police_calls',
        'transit_alerts',
        'go_transit_alerts',
    ];

    $snapshot = [];
    foreach ($tables as $table) {
        $snapshot[$table] = snapshotTableManual($connection, $table);
    }

    return $snapshot;
}

/**
 * @return array<string, int>
 */
function snapshotCountsManual(string $connection): array
{
    $tables = [
        'fire_incidents',
        'police_calls',
        'transit_alerts',
        'go_transit_alerts',
    ];

    $counts = [];
    foreach ($tables as $table) {
        $counts[$table] = DB::connection($connection)->table($table)->count();
    }

    return $counts;
}

$exitCode = 0;

try {
    if ($repoRoot === false) {
        throw new RuntimeException('Unable to resolve repository root path.');
    }

    if (! is_dir($workingDir)) {
        mkdir($workingDir, 0775, true);
    }

    touch($primaryDbPath);
    touch($secondaryDbPath);

    configureSqliteConnectionManual('manual_primary', $primaryDbPath);
    configureSqliteConnectionManual('manual_secondary', $secondaryDbPath);

    logInfo('=== Starting Manual Test: Production Data Migration Phase 4 Final Quality Gate ===');

    logInfo('Step 1: Prepare primary isolated database and seed deterministic alert data.');

    setDefaultConnectionManual('manual_primary');

    $migratePrimaryExit = Artisan::call('migrate:fresh', [
        '--database' => 'manual_primary',
        '--force' => true,
    ]);
    $migratePrimaryOutput = Artisan::output();

    assertTrueManual($migratePrimaryExit === 0, 'migrate:fresh succeeds for manual_primary', [
        'output' => $migratePrimaryOutput,
    ]);

    $seedTime = CarbonImmutable::parse('2026-02-09 12:00:00', 'UTC');

    FireIncident::factory()->count(3)->create([
        'created_at' => $seedTime->subMinutes(10),
        'updated_at' => $seedTime->subMinutes(5),
        'feed_updated_at' => $seedTime->subMinutes(5),
    ]);
    PoliceCall::factory()->count(3)->create([
        'created_at' => $seedTime->subMinutes(9),
        'updated_at' => $seedTime->subMinutes(4),
        'feed_updated_at' => $seedTime->subMinutes(4),
    ]);
    TransitAlert::factory()->count(3)->create([
        'created_at' => $seedTime->subMinutes(8),
        'updated_at' => $seedTime->subMinutes(3),
        'feed_updated_at' => $seedTime->subMinutes(3),
    ]);
    GoTransitAlert::factory()->count(3)->create([
        'created_at' => $seedTime->subMinutes(7),
        'updated_at' => $seedTime->subMinutes(2),
        'feed_updated_at' => $seedTime->subMinutes(2),
    ]);

    $primaryCounts = snapshotCountsManual('manual_primary');
    assertSameManual($primaryCounts, [
        'fire_incidents' => 3,
        'police_calls' => 3,
        'transit_alerts' => 3,
        'go_transit_alerts' => 3,
    ], 'primary source dataset contains expected row counts');

    $primarySnapshot = snapshotDatasetManual('manual_primary');

    logInfo('Step 2: Export seeders from primary dataset and run seeder verification command.');

    $mainSeederPath = $workingDir.'/ProductionDataSeeder.php';

    $exportExit = Artisan::call('db:export-to-seeder', [
        '--path' => $mainSeederPath,
        '--chunk' => 2,
        '--max-bytes' => 10485760,
    ]);
    $exportOutput = Artisan::output();

    assertTrueManual($exportExit === 0, 'db:export-to-seeder exits successfully', ['output' => $exportOutput]);
    assertTrueManual(file_exists($mainSeederPath), 'main seeder generated');

    $verifyExit = Artisan::call('db:verify-production-seed', ['--path' => $mainSeederPath]);
    $verifyOutput = Artisan::output();

    assertTrueManual($verifyExit === 0, 'db:verify-production-seed passes generated seeder', ['output' => $verifyOutput]);

    logInfo('Step 3: Wipe secondary isolated database, run generated seeders, and verify fidelity.');

    setDefaultConnectionManual('manual_secondary');

    $migrateSecondaryExit = Artisan::call('migrate:fresh', [
        '--database' => 'manual_secondary',
        '--force' => true,
    ]);
    $migrateSecondaryOutput = Artisan::output();

    assertTrueManual($migrateSecondaryExit === 0, 'migrate:fresh succeeds for manual_secondary', [
        'output' => $migrateSecondaryOutput,
    ]);

    $partFiles = glob($workingDir.'/ProductionDataSeeder_Part*.php');
    if (! is_array($partFiles)) {
        $partFiles = [];
    }
    sort($partFiles);

    $seederFiles = array_merge([$mainSeederPath], $partFiles);
    foreach ($seederFiles as $seederFile) {
        require_once $seederFile;
    }

    $mainSeederClass = 'Database\\Seeders\\'.pathinfo($mainSeederPath, PATHINFO_FILENAME);
    assertTrueManual(class_exists($mainSeederClass), 'generated main seeder class is autoloadable', [
        'class' => $mainSeederClass,
    ]);

    $seeder = app($mainSeederClass);
    assertTrueManual($seeder instanceof Seeder, 'generated main seeder extends Illuminate\\Database\\Seeder');

    $seeder->run();

    $secondaryCountsAfterFirstRun = snapshotCountsManual('manual_secondary');
    assertSameManual($secondaryCountsAfterFirstRun, $primaryCounts, 'secondary counts match source counts after first seeding');

    $secondarySnapshotAfterFirstRun = snapshotDatasetManual('manual_secondary');
    assertSameManual($secondarySnapshotAfterFirstRun, $primarySnapshot, 'secondary dataset matches source dataset exactly after first seeding');

    logInfo('Step 4: Re-run generated seeder to verify idempotency.');

    $seeder->run();

    $secondaryCountsAfterSecondRun = snapshotCountsManual('manual_secondary');
    assertSameManual($secondaryCountsAfterSecondRun, $primaryCounts, 'second seeding run does not change row counts');

    $secondarySnapshotAfterSecondRun = snapshotDatasetManual('manual_secondary');
    assertSameManual($secondarySnapshotAfterSecondRun, $primarySnapshot, 'second seeding run preserves exact dataset fidelity');

    logInfo('Step 5: Run lint and coverage quality checks for new modules.');

    $pintResult = runCommandManual(
        './vendor/bin/pint --test app/Console/Commands/ExportProductionData.php app/Console/Commands/VerifyProductionSeed.php tests/Feature/Commands/ExportProductionDataCommandTest.php tests/Feature/Commands/VerifyProductionSeedCommandTest.php tests/manual/verify_production_data_migration_phase_4_final_quality_gate.php',
        $repoRoot
    );

    assertTrueManual($pintResult['exit_code'] === 0, 'pint passes for production data migration modules', [
        'output' => $pintResult['output'],
    ]);

    $coverageCommand = "DB_CONNECTION=sqlite DB_DATABASE=':memory:' php artisan test tests/Feature/Commands/ExportProductionDataCommandTest.php tests/Feature/Commands/VerifyProductionSeedCommandTest.php --coverage --min=90";
    $coverageResult = runCommandManual($coverageCommand, $repoRoot);

    if ($coverageResult['exit_code'] !== 0 && str_contains($coverageResult['output'], 'No code coverage driver available')) {
        logWarn('Coverage driver is unavailable in this runtime; running equivalent command tests without coverage as fallback.', [
            'coverage_output' => $coverageResult['output'],
        ]);

        $testResult = runCommandManual(
            "DB_CONNECTION=sqlite DB_DATABASE=':memory:' php artisan test tests/Feature/Commands/ExportProductionDataCommandTest.php tests/Feature/Commands/VerifyProductionSeedCommandTest.php",
            $repoRoot
        );

        assertTrueManual($testResult['exit_code'] === 0, 'command tests pass without coverage fallback', [
            'output' => $testResult['output'],
        ]);
    } else {
        assertTrueManual($coverageResult['exit_code'] === 0, 'coverage command succeeds with >=90% threshold', [
            'output' => $coverageResult['output'],
        ]);
    }

    logInfo('=== Manual Test Completed Successfully ===');
} catch (Throwable $e) {
    $exitCode = 1;

    logError('Manual Test Failed', [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
} finally {
    deleteDirectoryRecursivelyManual($workingDir);

    logInfo('=== Test Run Finished ===');

    if ($exitCode === 0) {
        echo "\nResult: PASS\nLogs at: {$logFileRelative}\n";
    } else {
        echo "\nResult: FAIL\nLogs at: {$logFileRelative}\n";
    }

    exit($exitCode);
}
