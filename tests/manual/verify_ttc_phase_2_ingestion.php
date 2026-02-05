<?php

/**
 * Manual Test: TTC Transit Integration - Phase 2 Feed Ingestion
 * Generated: 2026-02-05
 * Purpose: Verify TTC feed service normalization plus command/job ingestion behavior.
 */

require __DIR__.'/../../vendor/autoload.php';

// Default manual verification runs to testing so Laravel loads `.env.testing`.
// Preserve an explicitly provided APP_ENV value if the caller set one.
if (getenv('APP_ENV') === false || getenv('APP_ENV') === '') {
    putenv('APP_ENV=testing');
    $_ENV['APP_ENV'] = 'testing';
    $_SERVER['APP_ENV'] = 'testing';
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

// Manual tests can delete data; only allow the dedicated testing database.
$expectedDatabase = 'gta_alerts_testing';
$connection = config('database.default');
$currentDatabase = config("database.connections.{$connection}.database");

if (! app()->environment('testing')) {
    exit("Error: Manual tests must run with APP_ENV=testing. Destructive test operations are disabled outside the testing environment and cannot be overridden.\n");
}

if ($currentDatabase !== $expectedDatabase) {
    exit("Error: Manual tests must use the '{$expectedDatabase}' database (current: {$currentDatabase}). Destructive test operations are disabled and cannot be overridden.\n");
}

umask(002);

use App\Jobs\FetchTransitAlertsJob;
use App\Models\TransitAlert;
use App\Services\TtcAlertsFeedService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

$testRunId = 'ttc_phase_2_ingestion_'.Carbon::now()->format('Y_m_d_His');
$logFileRelative = "storage/logs/manual_tests/{$testRunId}.log";
$logFile = storage_path("logs/manual_tests/{$testRunId}.log");

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

function assertTrue(bool $condition, string $label, array $ctx = []): void
{
    if (! $condition) {
        $message = "Assertion failed: {$label}.";
        logError($message, $ctx);
        throw new RuntimeException($message);
    }

    logInfo("Assertion passed: {$label}");
}

function assertEqual(mixed $actual, mixed $expected, string $label): void
{
    if ($actual !== $expected) {
        $message = "Assertion failed for {$label}.";
        logError($message, ['expected' => $expected, 'actual' => $actual]);
        throw new RuntimeException($message);
    }

    logInfo("Assertion passed: {$label}");
}

function assertContains(string $haystack, string $needle, string $label): void
{
    assertTrue(str_contains($haystack, $needle), $label, ['needle' => $needle, 'haystack' => $haystack]);
}

/**
 * @param  list<array<string, mixed>>  $alerts
 */
function buildFeedStub(CarbonInterface $updatedAt, array $alerts): TtcAlertsFeedService
{
    return new class($updatedAt, $alerts) extends TtcAlertsFeedService
    {
        /**
         * @param  list<array<string, mixed>>  $alerts
         */
        public function __construct(
            private readonly CarbonInterface $updatedAt,
            private readonly array $alerts
        ) {}

        public function fetch(): array
        {
            return [
                'updated_at' => $this->updatedAt,
                'alerts' => $this->alerts,
            ];
        }
    };
}

$exitCode = 0;
$txStarted = false;

try {
    try {
        DB::connection()->getPdo();
    } catch (Throwable $e) {
        throw new RuntimeException(
            "Database connection failed. If you're using Sail, run: ./scripts/init-testing-environment.sh",
            previous: $e
        );
    }

    logInfo('Boot context', [
        'app_env' => app()->environment(),
        'db_connection' => $connection,
        'db_database' => $currentDatabase,
    ]);

    if (! Schema::hasTable('transit_alerts')) {
        logInfo('transit_alerts missing; running migrations for testing database');
        Artisan::call('migrate', ['--force' => true]);
        logInfo('Migration output', ['output' => trim(Artisan::output())]);
    }

    DB::beginTransaction();
    $txStarted = true;

    logInfo('=== Starting Manual Test: TTC Transit Phase 2 Ingestion ===');

    logInfo('Phase 1: Verify feed service normalization across all 3 sources');

    Http::fake([
        'https://alerts.ttc.ca/api/alerts/live-alerts*' => Http::response([
            'lastUpdated' => '2026-02-03T04:41:06.633Z',
            'routes' => [
                [
                    'id' => '61748',
                    'alertType' => 'Planned',
                    'routeType' => 'Subway',
                    'route' => '1',
                    'title' => 'Line 1 service adjustment',
                    'description' => '&lt;script&gt;alert(1)&lt;/script&gt;<p>Shuttle <strong>buses</strong> will operate.</p>',
                    'severity' => 'Critical',
                    'effect' => 'REDUCED_SERVICE',
                    'causeDescription' => 'Other',
                    'activePeriod' => [
                        'start' => '2026-02-02T10:22:53.697Z',
                        'end' => '0001-01-01T00:00:00Z',
                    ],
                    'direction' => 'Both Ways',
                    'stopStart' => 'Finch',
                    'stopEnd' => 'Eglinton',
                    'url' => 'https://www.ttc.ca/service-alerts',
                ],
            ],
            'accessibility' => [],
            'siteWideCustom' => [],
            'generalCustom' => [],
            'stops' => [],
            'status' => 'success',
        ], 200),
        '*sxa/search/results*' => Http::sequence()
            ->push([
                'Results' => [
                    [
                        'Id' => '4976b805-daf7-43f7-96c1-c3da717a7877',
                        'Url' => '/service-advisories/Service-Changes/510-310',
                        'Html' => '<div><span class="field-route">510|310</span><span class="field-satitle">Temporary service change</span><span class="field-starteffectivedate">February 2, 2026 - 11:00 PM</span><span class="field-endeffectivedate">February 5, 2026 - 04:00 AM</span></div>',
                    ],
                ],
            ], 200)
            ->push(['Results' => []], 200)
            ->push(['Results' => []], 200)
            ->push(['Results' => []], 200),
        'https://www.ttc.ca/service-advisories/Streetcar-Service-Changes*' => Http::response(
            <<<'HTML'
            <html><body>
            <section class="hero">
              <h2>Welcome to TTC advisories</h2>
              <p>General info for riders.</p>
            </section>
            <article class="streetcar-advisory">
              <h3>504 Temporary service change overnight</h3>
              <p>Streetcars replaced by buses due to track work.</p>
              <a href="/service-advisories/Streetcar-Service-Changes">Details</a>
            </article>
            </body></html>
            HTML,
            200
        ),
    ]);

    $service = app(TtcAlertsFeedService::class);
    $normalized = $service->fetch();

    assertTrue($normalized['updated_at'] instanceof CarbonInterface, 'service returns updated_at as Carbon');

    $apiAlert = collect($normalized['alerts'])->firstWhere('external_id', 'api:61748');
    assertTrue(is_array($apiAlert), 'service includes normalized API alert');
    assertEqual($apiAlert['source_feed'] ?? null, 'live-api', 'API alert source_feed');
    assertEqual($apiAlert['description'] ?? null, 'alert(1)Shuttle buses will operate.', 'description is sanitized');
    assertTrue(! str_contains((string) ($apiAlert['description'] ?? ''), '<script>'), 'description does not contain script tag');
    assertTrue(($apiAlert['active_period_end'] ?? 'not-null') === null, 'sentinel end timestamp normalized to null');
    assertTrue(($apiAlert['active_period_start'] ?? null) instanceof CarbonInterface, 'active_period_start normalized to Carbon');

    $sxaAlert = collect($normalized['alerts'])->firstWhere('external_id', 'sxa:4976b805-daf7-43f7-96c1-c3da717a7877');
    assertTrue(is_array($sxaAlert), 'service includes normalized SXA alert');
    assertEqual($sxaAlert['route'] ?? null, '510,310', 'SXA route pipe delimiter normalized');

    $staticAlerts = collect($normalized['alerts'])->where('source_feed', 'static')->values();
    assertEqual($staticAlerts->count(), 1, 'static parser ignores non-advisory sections');
    assertEqual($staticAlerts[0]['title'] ?? null, '504 Temporary service change overnight', 'static advisory title extracted');

    logInfo('Phase 2: Verify source-1 failure is fatal');

    Http::fake([
        'https://alerts.ttc.ca/api/alerts/live-alerts*' => Http::response('error', 500),
    ]);

    $fatalRaised = false;

    try {
        $service->fetch();
    } catch (RuntimeException $e) {
        $fatalRaised = true;
        assertContains($e->getMessage(), 'TTC live alerts request failed: 500', 'fatal source-1 exception message');
    }

    assertTrue($fatalRaised, 'source-1 failure raises RuntimeException');

    logInfo('Phase 3: Verify source-2/source-3 failures are best effort');

    Http::fake([
        'https://alerts.ttc.ca/api/alerts/live-alerts*' => Http::response([
            'lastUpdated' => '2026-02-03T04:41:06.633Z',
            'routes' => [
                [
                    'id' => '70001',
                    'title' => 'Primary feed alert',
                    'activePeriod' => [
                        'start' => '2026-02-02T10:22:53.697Z',
                        'end' => '2026-02-05T23:00:00Z',
                    ],
                ],
            ],
            'accessibility' => [],
            'siteWideCustom' => [],
            'generalCustom' => [],
            'stops' => [],
        ], 200),
        '*sxa/search/results*' => Http::response(['unexpected' => true], 200),
        'https://www.ttc.ca/service-advisories/Streetcar-Service-Changes*' => Http::response('down', 500),
    ]);

    $bestEffort = $service->fetch();
    assertEqual(count($bestEffort['alerts']), 1, 'service continues with primary alerts when secondary feeds fail');
    assertEqual($bestEffort['alerts'][0]['external_id'] ?? null, 'api:70001', 'best-effort result keeps primary alert');

    logInfo('Phase 4: Verify transit:fetch-alerts sync and stale deactivation');

    TransitAlert::query()->delete();
    TransitAlert::factory()->create([
        'external_id' => 'api:old-alert',
        'title' => 'Old alert',
        'is_active' => true,
    ]);
    TransitAlert::factory()->create([
        'external_id' => 'api:stay-alert',
        'title' => 'Outdated title',
        'is_active' => true,
    ]);

    $feedUpdatedAt = CarbonImmutable::parse('2026-02-05T15:00:00Z');

    app()->instance(TtcAlertsFeedService::class, buildFeedStub($feedUpdatedAt, [
        [
            'external_id' => 'api:stay-alert',
            'source_feed' => 'live-api',
            'alert_type' => 'Planned',
            'route_type' => 'Subway',
            'route' => '1',
            'title' => 'Updated title',
            'description' => 'Updated description',
            'severity' => 'Critical',
            'effect' => 'REDUCED_SERVICE',
            'cause' => 'Other',
            'active_period_start' => CarbonImmutable::parse('2026-02-05T14:00:00Z'),
            'active_period_end' => null,
            'direction' => 'Both Ways',
            'stop_start' => 'Finch',
            'stop_end' => 'Eglinton',
            'url' => 'https://www.ttc.ca/service-alerts',
        ],
        [
            'external_id' => 'sxa:4976b805-daf7-43f7-96c1-c3da717a7877',
            'source_feed' => 'sxa',
            'alert_type' => 'Planned',
            'route_type' => null,
            'route' => '510,310',
            'title' => 'Temporary service change',
            'description' => null,
            'severity' => null,
            'effect' => null,
            'cause' => null,
            'active_period_start' => CarbonImmutable::parse('2026-02-03T04:00:00Z'),
            'active_period_end' => CarbonImmutable::parse('2026-02-05T09:00:00Z'),
            'direction' => null,
            'stop_start' => null,
            'stop_end' => null,
            'url' => 'https://www.ttc.ca/service-advisories/Service-Changes/510-310',
        ],
    ]));

    $commandExit = Artisan::call('transit:fetch-alerts');
    $commandOutput = Artisan::output();

    assertEqual($commandExit, 0, 'transit:fetch-alerts exits successfully');
    assertContains($commandOutput, 'Fetching TTC transit alerts...', 'command output includes start message');
    assertContains($commandOutput, 'Done. 2 active alerts synced, 1 marked inactive.', 'command output includes sync summary');

    $stale = TransitAlert::query()->where('external_id', 'api:old-alert')->first();
    $updated = TransitAlert::query()->where('external_id', 'api:stay-alert')->first();
    $inserted = TransitAlert::query()->where('external_id', 'sxa:4976b805-daf7-43f7-96c1-c3da717a7877')->first();

    assertTrue($stale !== null && $stale->is_active === false, 'stale alert marked inactive');
    assertTrue($updated !== null && $updated->is_active === true, 'existing alert remains active');
    assertEqual($updated?->title, 'Updated title', 'existing alert updated');
    assertTrue($inserted !== null && $inserted->is_active === true, 'new alert inserted and active');
    assertEqual(
        optional($updated?->feed_updated_at)->format('Y-m-d H:i:s'),
        '2026-02-05 15:00:00',
        'feed_updated_at persisted from sync timestamp'
    );

    logInfo('Phase 5: Verify FetchTransitAlertsJob invokes command behavior');

    TransitAlert::query()->delete();
    TransitAlert::factory()->create([
        'external_id' => 'api:job-old',
        'title' => 'Job old',
        'is_active' => true,
    ]);

    app()->instance(TtcAlertsFeedService::class, buildFeedStub(
        CarbonImmutable::parse('2026-02-05T16:00:00Z'),
        [[
            'external_id' => 'api:job-new',
            'source_feed' => 'live-api',
            'alert_type' => 'Planned',
            'route_type' => 'Bus',
            'route' => '29',
            'title' => 'Job created alert',
            'description' => 'Bus diversion',
            'severity' => 'Minor',
            'effect' => 'DETOUR',
            'cause' => 'Construction',
            'active_period_start' => CarbonImmutable::parse('2026-02-05T15:30:00Z'),
            'active_period_end' => null,
            'direction' => 'Northbound',
            'stop_start' => null,
            'stop_end' => null,
            'url' => 'https://www.ttc.ca/service-alerts',
        ]]
    ));

    $job = new FetchTransitAlertsJob;
    assertEqual($job->tries, 3, 'job tries configured');
    assertEqual($job->backoff, 30, 'job backoff configured');
    $job->handle();

    $jobOld = TransitAlert::query()->where('external_id', 'api:job-old')->first();
    $jobNew = TransitAlert::query()->where('external_id', 'api:job-new')->first();

    assertTrue($jobOld !== null && $jobOld->is_active === false, 'job path deactivates stale alert');
    assertTrue($jobNew !== null && $jobNew->is_active === true, 'job path creates/activates current alert');

    logInfo('=== Manual Test Completed Successfully ===');
} catch (Throwable $e) {
    $exitCode = 1;
    logError('Manual Test Failed', [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
} finally {
    if ($txStarted) {
        try {
            if (DB::connection()->transactionLevel() > 0) {
                DB::rollBack();
                logInfo('Transaction rolled back (Database preserved).');
            }
        } catch (Throwable) {
        }
    }

    logInfo('=== Test Run Finished ===');

    if ($exitCode === 0) {
        echo "\nResult: PASS\nLogs at: {$logFileRelative}\n";
    } else {
        echo "\nResult: FAIL\nLogs at: {$logFileRelative}\n";
    }

    exit($exitCode);
}
