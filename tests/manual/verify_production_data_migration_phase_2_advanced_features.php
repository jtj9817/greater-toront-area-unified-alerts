<?php

/**
 * Manual Test: Production Data Migration - Phase 2 Advanced Features & Splitting
 * Generated: 2026-02-08
 * Purpose: Verify split seeder generation and db:verify-production-seed command behavior.
 */

require __DIR__.'/../../vendor/autoload.php';

// Force testing environment even if caller shell (e.g., tmux) does not pass APP_ENV through.
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
    fwrite(STDERR, "Error: Do not run manual tests as root. Use `./vendor/bin/sail shell` (or `./vendor/bin/sail php ...`).\n");
    fwrite(STDERR, "If you really need root, re-run with ALLOW_ROOT_MANUAL_TESTS=1 (not recommended).\n");
    exit(1);
}

if (! app()->environment('testing')) {
    exit("Error: Manual tests must run with APP_ENV=testing.\n");
}

$expectedConnection = 'mysql';
$connection = config('database.default');

if ($connection !== $expectedConnection) {
    exit("Error: Manual tests must use '{$expectedConnection}' connection (current: {$connection}).\n");
}

$expectedDatabase = env('TEST_DB_DATABASE', 'gta_alerts_testing');
$currentDatabase = config("database.connections.{$connection}.database");

if ($currentDatabase !== $expectedDatabase) {
    exit("Error: Manual tests must use testing DB '{$expectedDatabase}' (current: {$currentDatabase}).\n");
}

$expectedHost = 'mysql-testing';
$currentHost = config("database.connections.{$connection}.host");

if ($currentHost !== $expectedHost) {
    exit("Error: Manual tests must target testing DB host '{$expectedHost}' (current: {$currentHost}).\n");
}

umask(002);

use App\Models\FireIncident;
use App\Models\GoTransitAlert;
use App\Models\PoliceCall;
use App\Models\TransitAlert;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

$testRunId = 'production_data_migration_phase_2_'.Carbon::now()->format('Y_m_d_His');
$logFileRelative = "storage/logs/manual_tests/{$testRunId}.log";
$logFile = storage_path("logs/manual_tests/{$testRunId}.log");
$workingDir = storage_path("app/private/manual_tests/{$testRunId}");

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

function assertContainsManual(string $needle, string $haystack, string $label): void
{
    assertTrueManual(str_contains($haystack, $needle), $label, ['needle' => $needle]);
}

function lintPhpFileManual(string $path): void
{
    $output = [];
    $exitCode = 0;

    exec('php -l '.escapeshellarg($path).' 2>&1', $output, $exitCode);

    assertTrueManual(
        $exitCode === 0,
        "PHP lint passes for {$path}",
        ['output' => implode("\n", $output)]
    );
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

$exitCode = 0;
$transactionStarted = false;

try {
    try {
        DB::connection()->getPdo();
    } catch (Throwable $e) {
        throw new RuntimeException(
            "Database connection failed. Start Sail with testing profile and run this script via scripts/run-manual-test.sh.",
            previous: $e
        );
    }

    if (! is_dir($workingDir)) {
        mkdir($workingDir, 0775, true);
    }

    DB::beginTransaction();
    $transactionStarted = true;

    logInfo('=== Starting Manual Test: Production Data Migration Phase 2 ===');

    logInfo('Step 1: Build a large deterministic dataset (testing DB only).');

    FireIncident::factory()->count(60)->create([
        'units_dispatched' => str_repeat('P100, ', 50),
    ]);

    PoliceCall::factory()->count(60)->create();
    TransitAlert::factory()->count(60)->create();
    GoTransitAlert::factory()->count(60)->create();

    assertTrueManual(FireIncident::count() >= 60, 'fire incidents created');
    assertTrueManual(PoliceCall::count() >= 60, 'police calls created');
    assertTrueManual(TransitAlert::count() >= 60, 'transit alerts created');
    assertTrueManual(GoTransitAlert::count() >= 60, 'go transit alerts created');

    logInfo('Step 2: Export split seeders with forced max-bytes threshold.');

    $mainSeederPath = $workingDir.'/ProductionDataSeeder.php';

    $exportExitCode = Artisan::call('db:export-to-seeder', [
        '--path' => $mainSeederPath,
        '--chunk' => 10,
        '--max-bytes' => 5000,
    ]);

    $exportOutput = Artisan::output();

    assertTrueManual($exportExitCode === 0, 'db:export-to-seeder exits successfully', ['output' => $exportOutput]);
    assertTrueManual(file_exists($mainSeederPath), 'main seeder file generated');

    $partFiles = glob($workingDir.'/ProductionDataSeeder_Part*.php');
    sort($partFiles);

    assertTrueManual(is_array($partFiles), 'split part file list generated');
    assertTrueManual(count($partFiles) >= 2, 'multiple split part seeders generated', ['count' => count($partFiles)]);

    $mainContents = file_get_contents($mainSeederPath);
    assertTrueManual($mainContents !== false, 'main seeder readable');
    assertContainsManual('$this->call([', $mainContents, 'main seeder links part seeders');
    assertContainsManual('ProductionDataSeeder_Part1::class', $mainContents, 'main seeder includes part1');

    foreach ($partFiles as $partFile) {
        $partContents = file_get_contents($partFile);
        assertTrueManual($partContents !== false, "{$partFile} is readable");
        assertContainsManual('insertOrIgnore', $partContents, "{$partFile} includes insertOrIgnore payload");
        lintPhpFileManual($partFile);
    }

    lintPhpFileManual($mainSeederPath);

    logInfo('Step 3: Verify generated seeders via db:verify-production-seed.');

    $verifyExitCode = Artisan::call('db:verify-production-seed', ['--path' => $mainSeederPath]);
    $verifyOutput = Artisan::output();

    assertTrueManual($verifyExitCode === 0, 'db:verify-production-seed passes valid export', ['output' => $verifyOutput]);
    assertContainsManual('Verification passed', $verifyOutput, 'verification success message emitted');

    logInfo('Step 4: Negative verification check - syntax error seeder.');

    $syntaxErrorPath = $workingDir.'/BrokenProductionDataSeeder.php';
    file_put_contents($syntaxErrorPath, "<?php\nclass BrokenSeeder {\n public function run() {\n");

    $syntaxExitCode = Artisan::call('db:verify-production-seed', ['--path' => $syntaxErrorPath]);
    $syntaxOutput = Artisan::output();

    assertTrueManual($syntaxExitCode === 1, 'verification fails for syntax error seeder', ['output' => $syntaxOutput]);
    assertContainsManual('Syntax check failed', $syntaxOutput, 'syntax failure message emitted');

    logInfo('Step 5: Negative verification check - missing required tables.');

    $missingTablesPath = $workingDir.'/MissingTablesSeeder.php';
    file_put_contents($missingTablesPath, <<<'PHP'
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProductionDataSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('fire_incidents')->insertOrIgnore([
            [
                'id' => 1,
                'event_num' => 'F26000001',
                'created_at' => '2026-02-07 00:00:00',
                'updated_at' => '2026-02-07 00:00:00',
            ],
        ]);
    }
}
PHP);

    $missingExitCode = Artisan::call('db:verify-production-seed', ['--path' => $missingTablesPath]);
    $missingOutput = Artisan::output();

    assertTrueManual($missingExitCode === 1, 'verification fails when required table blocks are missing', ['output' => $missingOutput]);
    assertContainsManual('Missing export blocks for tables', $missingOutput, 'missing table failure message emitted');

    logInfo('=== Manual Test Completed Successfully ===');
} catch (Throwable $e) {
    $exitCode = 1;

    logError('Manual Test Failed', [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
} finally {
    if ($transactionStarted) {
        try {
            if (DB::connection()->transactionLevel() > 0) {
                DB::rollBack();
                logInfo('Transaction rolled back (testing DB preserved).');
            }
        } catch (Throwable $rollbackException) {
            logError('Rollback failed', ['message' => $rollbackException->getMessage()]);
        }
    }

    deleteDirectoryRecursivelyManual($workingDir);

    logInfo('=== Test Run Finished ===');

    if ($exitCode === 0) {
        echo "\nResult: PASS\nLogs at: {$logFileRelative}\n";
    } else {
        echo "\nResult: FAIL\nLogs at: {$logFileRelative}\n";
    }

    exit($exitCode);
}
