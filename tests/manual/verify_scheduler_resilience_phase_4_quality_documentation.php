<?php

/**
 * Manual Test: Scheduler Resilience - Phase 4 Quality & Documentation
 * Generated: 2026-02-18
 *
 * Purpose:
 * - Verify Phase 4 documentation deliverables exist and include key operational guidance:
 *   - Empty feed protection (`ALLOW_EMPTY_FEEDS`)
 *   - Overlap lock behavior (10-minute expiry + 30-second retry release)
 *   - Failed job pruning policy (`queue:prune-failed --hours=168`)
 *   - Scheduler + queue runbooks
 * - Verify Phase 4 bug fix coverage via targeted test suites:
 *   - GO Transit envelope parsing (Notifications / SaagNotifications)
 *   - Circuit breaker exception type and edge behavior
 *   - Data sanity guardrails
 *   - Command-level resilience (QueryException rethrow, per-record continue)
 *
 * Run:
 * - php tests/manual/verify_scheduler_resilience_phase_4_quality_documentation.php
 *
 * Optional:
 * - RUN_TRACK_TESTS=0 to skip running Pest tests (docs-only verification)
 * - RUN_COVERAGE=1 to attempt coverage (requires Xdebug/PCOV available)
 * - AUTO_CLEAR_CONFIG_CACHE=1 to automatically run `php artisan config:clear`
 *   before executing subprocess test/coverage commands (recommended if you
 *   commonly run `php artisan config:cache` locally).
 */

require __DIR__.'/../../vendor/autoload.php';

// Default manual verification runs to testing so Laravel loads `.env.testing`.
// Preserve an explicitly provided APP_ENV value if the caller set one.
if (getenv('APP_ENV') === false || getenv('APP_ENV') === '') {
    putenv('APP_ENV=testing');
    $_ENV['APP_ENV'] = 'testing';
    $_SERVER['APP_ENV'] = 'testing';
}

// Manual verification should be runnable without Docker/Sail. If the caller didn't
// specify DB/CACHE/QUEUE settings, prefer ephemeral local configuration.
if (getenv('DB_CONNECTION') === false || getenv('DB_CONNECTION') === '') {
    putenv('DB_CONNECTION=sqlite');
    $_ENV['DB_CONNECTION'] = 'sqlite';
    $_SERVER['DB_CONNECTION'] = 'sqlite';
}

if (getenv('DB_DATABASE') === false || getenv('DB_DATABASE') === '') {
    putenv('DB_DATABASE=:memory:');
    $_ENV['DB_DATABASE'] = ':memory:';
    $_SERVER['DB_DATABASE'] = ':memory:';
}

if (getenv('CACHE_STORE') === false || getenv('CACHE_STORE') === '') {
    putenv('CACHE_STORE=array');
    $_ENV['CACHE_STORE'] = 'array';
    $_SERVER['CACHE_STORE'] = 'array';
}

if (getenv('QUEUE_CONNECTION') === false || getenv('QUEUE_CONNECTION') === '') {
    putenv('QUEUE_CONNECTION=sync');
    $_ENV['QUEUE_CONNECTION'] = 'sync';
    $_SERVER['QUEUE_CONNECTION'] = 'sync';
}

if (getenv('SESSION_DRIVER') === false || getenv('SESSION_DRIVER') === '') {
    putenv('SESSION_DRIVER=array');
    $_ENV['SESSION_DRIVER'] = 'array';
    $_SERVER['SESSION_DRIVER'] = 'array';
}

// Some manual test environments do not provide APP_KEY in `.env.testing`.
// Use a deterministic testing-only fallback so app boot does not fail.
$manualTestEnv = getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? null);
if ($manualTestEnv === 'testing' && (getenv('APP_KEY') === false || getenv('APP_KEY') === '')) {
    $fallbackAppKey = 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';
    putenv("APP_KEY={$fallbackAppKey}");
    $_ENV['APP_KEY'] = $fallbackAppKey;
    $_SERVER['APP_KEY'] = $fallbackAppKey;
}

$app = require_once __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Prevent production execution.
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

umask(002);

use App\Services\FeedCircuitBreaker;
use App\Services\FeedCircuitBreakerOpenException;
use App\Services\GoTransitFeedService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

$testRunId = 'scheduler_resilience_phase_4_quality_documentation_'.Carbon::now()->format('Y_m_d_His');
$logFileRelative = "storage/logs/manual_tests/{$testRunId}.log";
$logFile = storage_path("logs/manual_tests/{$testRunId}.log");
$logDir = dirname($logFile);

if (! is_dir($logDir) && ! mkdir($logDir, 0775, true) && ! is_dir($logDir)) {
    fwrite(STDERR, "Error: Failed to create log directory: {$logDir}\n");
    exit(1);
}

if (! file_exists($logFile) && @touch($logFile) === false) {
    fwrite(STDERR, "Error: Failed to create log file: {$logFile}\n");
    exit(1);
}

@chmod($logFile, 0664);

config(['logging.channels.manual_test' => [
    'driver' => 'single',
    'path' => $logFile,
    'level' => 'debug',
]]);

// Route all app logs (warnings/errors under test) to this manual log file.
config(['logging.default' => 'manual_test']);

function logInfo(string $msg, array $ctx = []): void
{
    Log::channel('manual_test')->info($msg, $ctx);
    $suffix = $ctx === [] ? '' : ' '.json_encode($ctx, JSON_UNESCAPED_SLASHES);
    echo "[INFO] {$msg}{$suffix}\n";
}

function logWarning(string $msg, array $ctx = []): void
{
    Log::channel('manual_test')->warning($msg, $ctx);
    $suffix = $ctx === [] ? '' : ' '.json_encode($ctx, JSON_UNESCAPED_SLASHES);
    echo "[WARN] {$msg}{$suffix}\n";
}

function logError(string $msg, array $ctx = []): void
{
    Log::channel('manual_test')->error($msg, $ctx);
    $suffix = $ctx === [] ? '' : ' '.json_encode($ctx, JSON_UNESCAPED_SLASHES);
    echo "[ERROR] {$msg}{$suffix}\n";
}

function assertTrue(bool $condition, string $label, array $ctx = []): void
{
    if (! $condition) {
        $message = "Assertion failed: {$label}.";
        logError($message, $ctx);
        throw new RuntimeException($message);
    }

    logInfo("Assertion passed: {$label}");
}

function assertContainsText(string $needle, string $haystack, string $label): void
{
    assertTrue(str_contains($haystack, $needle), $label, ['needle' => $needle]);
}

function readFileContents(string $relativePath): string
{
    $absolutePath = base_path($relativePath);
    assertTrue(file_exists($absolutePath), "file exists: {$relativePath}");

    $contents = file_get_contents($absolutePath);
    assertTrue(is_string($contents), "file is readable: {$relativePath}");

    return $contents;
}

/**
 * @return array{exit_code: int|null, output: string}
 */
function runCommand(string $command, string $label): array
{
    logInfo("Running command: {$label}", ['command' => $command]);

    $process = new Process(['bash', '-lc', $command], base_path());
    $process->setTimeout(null);

    $output = '';
    $process->run(function (string $type, string $buffer) use (&$output): void {
        $output .= $buffer;
        echo $buffer;
    });

    $exitCode = $process->getExitCode();

    Log::channel('manual_test')->info("Command output: {$label}", [
        'exit_code' => $exitCode,
        'output' => $output,
    ]);

    return [
        'exit_code' => $exitCode,
        'output' => $output,
    ];
}

function hasCoverageDriver(): bool
{
    return extension_loaded('xdebug') || extension_loaded('pcov');
}

function configIsCached(): bool
{
    $path = base_path('bootstrap/cache/config.php');

    return file_exists($path);
}

$autoClearConfigCache = getenv('AUTO_CLEAR_CONFIG_CACHE') === '1';

$exitCode = 0;

try {
    logInfo('=== Manual Test: Scheduler Resilience Phase 4 Quality & Documentation ===');

    logInfo('Phase 1: Verify documentation + artifact deliverables');

    $envExample = readFileContents('.env.example');
    assertContainsText('ALLOW_EMPTY_FEEDS=false', $envExample, '.env.example documents ALLOW_EMPTY_FEEDS');

    $coverageHelper = readFileContents('scripts/setup-coverage.sh');
    assertContainsText('--check-only', $coverageHelper, 'coverage helper supports check-only mode');

    $productionSchedulerDoc = readFileContents('docs/backend/production-scheduler.md');
    assertContainsText('Empty Feed Strategy (`ALLOW_EMPTY_FEEDS`)', $productionSchedulerDoc, 'production scheduler doc includes empty feed strategy section');
    assertContainsText('withoutOverlapping(10)', $productionSchedulerDoc, 'production scheduler doc mentions overlap protection');
    assertContainsText('releases locks after 30 seconds', $productionSchedulerDoc, 'production scheduler doc documents 30-second lock release');
    assertContainsText('queue:prune-failed --hours=168', $productionSchedulerDoc, 'production scheduler doc mentions failed job pruning command');

    $maintenanceDoc = readFileContents('docs/backend/maintenance.md');
    assertContainsText('queue:prune-failed --hours=168', $maintenanceDoc, 'maintenance doc mentions failed job pruning command');

    $sceneIntelDoc = readFileContents('docs/backend/scene-intel.md');
    assertContainsText('Failure rate monitoring', $sceneIntelDoc, 'scene intel doc includes failure rate monitoring section');
    assertContainsText('Job retry policy', $sceneIntelDoc, 'scene intel doc includes job retry policy section');

    $schedulerRunbook = readFileContents('docs/runbooks/scheduler-troubleshooting.md');
    assertContainsText('ALLOW_EMPTY_FEEDS=true', $schedulerRunbook, 'scheduler runbook documents temporary allow-empty-feeds override');
    assertContainsText('circuit breaker', strtolower($schedulerRunbook), 'scheduler runbook mentions circuit breaker behavior');

    $queueRunbook = readFileContents('docs/runbooks/queue-troubleshooting.md');
    assertContainsText('queue:prune-failed --hours=168', $queueRunbook, 'queue runbook mentions failed job pruning command');

    $readme = readFileContents('README.md');
    assertContainsText('ALLOW_EMPTY_FEEDS=false', $readme, 'README references empty feed protection');
    assertContainsText('Scheduler Runbooks', $readme, 'README links scheduler runbooks');

    $docsReadme = readFileContents('docs/README.md');
    assertContainsText('scheduler-troubleshooting.md', $docsReadme, 'docs/README lists scheduler troubleshooting runbook');
    assertContainsText('queue-troubleshooting.md', $docsReadme, 'docs/README lists queue troubleshooting runbook');

    logInfo('Phase 2: Verify Phase 4 behavior via direct service checks');

    config([
        'feeds.circuit_breaker.enabled' => true,
        'feeds.circuit_breaker.threshold' => 1,
        'feeds.circuit_breaker.ttl_seconds' => 60,
        'feeds.allow_empty_feeds' => true,
        'cache.default' => 'array',
    ]);

    Cache::flush();

    $breaker = app(FeedCircuitBreaker::class);
    $breaker->recordFailure('toronto_fire', new RuntimeException('synthetic failure'));

    $caught = null;
    try {
        $breaker->throwIfOpen('toronto_fire');
    } catch (Throwable $e) {
        $caught = $e;
    }

    assertTrue($caught instanceof FeedCircuitBreakerOpenException, 'circuit breaker open throws FeedCircuitBreakerOpenException', [
        'caught_class' => is_object($caught) ? $caught::class : null,
    ]);

    $failuresAfter = Cache::get('feeds:circuit_breaker:toronto_fire');
    assertTrue($failuresAfter === 1, 'throwIfOpen does not increment failure count', ['failures' => $failuresAfter]);

    Cache::flush();

    Http::fake([
        'https://api.metrolinx.com/external/go/serviceupdate/en/all*' => Http::response([
            'LastUpdated' => '2026-02-18T00:00:00Z',
            'Trains' => ['Train' => [[
                'Code' => 'LW',
                'Name' => 'Lakeshore West',
                'LineColour' => '#0099ff',
                'Notifications' => ['Notification' => [[
                    'MessageSubject' => 'Service disruption',
                    'MessageBody' => '<p>Expect delays</p>',
                    'PostedDateTime' => '2026-02-18T00:00:00Z',
                    'SubCategory' => 'Delay',
                    'Status' => 'Active',
                ]]],
                'SaagNotifications' => ['SaagNotification' => [[
                    'TripNumbers' => ['123'],
                    'Direction' => 'E',
                    'HeadSign' => 'Union Station',
                    'DelayDuration' => '00:10:00',
                    'DepartureTimeDisplay' => '12:00',
                    'ArrivalTimeTimeDisplay' => '12:30',
                    'Status' => 'Delayed',
                    'PostedDateTime' => '2026-02-18T00:00:00Z',
                ]]],
            ]]],
            'Buses' => ['Bus' => []],
            'Stations' => ['Station' => []],
        ], 200),
    ]);

    $goService = app(GoTransitFeedService::class);
    $payload = $goService->fetch();

    assertTrue(($payload['updated_at'] ?? null) === '2026-02-18T00:00:00Z', 'go transit fetch returns updated_at');
    assertTrue(is_array($payload['alerts'] ?? null), 'go transit fetch returns alerts list');
    assertTrue(count($payload['alerts']) === 2, 'go transit envelope parsing returns two alerts (notification + saag)', [
        'count' => is_array($payload['alerts'] ?? null) ? count($payload['alerts']) : null,
    ]);

    logInfo('Phase 3: Execute Phase 4 track test suites (optional)');

    $runTrackTests = getenv('RUN_TRACK_TESTS');
    if ($runTrackTests === '0') {
        logWarning('Skipping track tests (RUN_TRACK_TESTS=0).');
    } else {
        if (configIsCached()) {
            if ($autoClearConfigCache) {
                runCommand('APP_ENV=testing php artisan config:clear', 'Clear config cache');
            } else {
                throw new RuntimeException(
                    'Refusing to run subprocess tests while config is cached.'
                    .' Env overrides like DB_CONNECTION=sqlite DB_DATABASE=:memory: may be ignored when'
                    .' bootstrap/cache/config.php exists. Run `php artisan config:clear` or re-run with'
                    .' AUTO_CLEAR_CONFIG_CACHE=1.'
                );
            }
        }

        $envPrefix = 'APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: CACHE_STORE=array QUEUE_CONNECTION=sync SESSION_DRIVER=array';

        $tests = runCommand(
            "{$envPrefix} php artisan test "
            .'tests/Feature/Console/SchedulerResiliencePhase1Test.php '
            .'tests/Feature/Console/SchedulerResiliencePhase2Test.php '
            .'tests/Feature/Console/SchedulerResiliencePhase3Test.php '
            .'tests/Feature/Services/FeedCircuitBreakerTest.php '
            .'tests/Feature/Services/FeedDataSanityTest.php '
            .'tests/Feature/Services/TorontoFireFeedServiceTest.php '
            .'tests/Feature/Services/TorontoPoliceFeedServiceTest.php '
            .'tests/Feature/Services/GoTransitFeedServiceTest.php '
            .'tests/Feature/Services/TtcAlertsFeedServiceTest.php '
            .'tests/Feature/Console/Commands/FetchFireIncidentsCommandTest.php '
            .'tests/Feature/Console/Commands/FetchPoliceCallsCommandTest.php '
            .'tests/Feature/Console/Commands/FetchTransitAlertsCommandTest.php',
            'Scheduler resilience Phase 1-4 test suites'
        );

        assertTrue($tests['exit_code'] === 0, 'scheduler resilience test suites pass', ['exit_code' => $tests['exit_code']]);
    }

    $runCoverage = getenv('RUN_COVERAGE');
    if ($runCoverage === '1') {
        if (! hasCoverageDriver()) {
            logWarning('Skipping coverage (RUN_COVERAGE=1) because no coverage driver (Xdebug/PCOV) is available.', [
                'hint' => 'See scripts/setup-coverage.sh for Sail-based setup.',
            ]);
        } else {
            if (configIsCached()) {
                if ($autoClearConfigCache) {
                    runCommand('APP_ENV=testing php artisan config:clear', 'Clear config cache');
                } else {
                    throw new RuntimeException(
                        'Refusing to run subprocess coverage while config is cached.'
                        .' Env overrides like DB_CONNECTION=sqlite DB_DATABASE=:memory: may be ignored when'
                        .' bootstrap/cache/config.php exists. Run `php artisan config:clear` or re-run with'
                        .' AUTO_CLEAR_CONFIG_CACHE=1.'
                    );
                }
            }

            $envPrefix = 'APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: CACHE_STORE=array QUEUE_CONNECTION=sync SESSION_DRIVER=array';

            $coverage = runCommand(
                "{$envPrefix} ./vendor/bin/pest --coverage --min=90",
                'Coverage gate (min 90%)'
            );

            assertTrue($coverage['exit_code'] === 0, 'coverage gate passes', ['exit_code' => $coverage['exit_code']]);
        }
    } else {
        logInfo('Skipping coverage (set RUN_COVERAGE=1 to enable).');
    }

    logInfo('=== Manual Test Completed Successfully ===');
} catch (Throwable $e) {
    $exitCode = 1;
    logError('Manual Test Failed', [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
} finally {
    logInfo('Manual test log file', ['path' => $logFileRelative]);
}

exit($exitCode);
