<?php

/**
 * Manual Test: Scheduler Resilience - Phase 3 Data Integrity & Maintenance
 * Generated: 2026-02-18
 *
 * Purpose:
 * - Verify failed job pruning is scheduled daily (`queue:prune-failed --hours=168`)
 * - Verify feed circuit breaker opens after repeated failures and blocks HTTP calls while open
 * - Verify data sanity warnings:
 *   - Future timestamps (> grace window) log warnings
 *   - Police coordinates outside GTA bounds log warnings
 * - Verify police pagination memory safety limit triggers a clear exception (no OOM)
 * - Verify Scene Intel failure rate monitoring logs and outputs warning when > 50%
 * - Verify TTC sentinel `lastUpdated` timestamp is rejected (strict ISO parsing)
 *
 * Run (Sail):
 * - ./vendor/bin/sail php tests/manual/verify_scheduler_resilience_phase_3_data_integrity_maintenance.php
 */

require __DIR__.'/../../vendor/autoload.php';

// Default manual verification runs to testing so Laravel loads `.env.testing`.
// Preserve an explicitly provided APP_ENV value if the caller set one.
if (getenv('APP_ENV') === false || getenv('APP_ENV') === '') {
    putenv('APP_ENV=testing');
    $_ENV['APP_ENV'] = 'testing';
    $_SERVER['APP_ENV'] = 'testing';
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

use App\Services\GoTransitFeedService;
use App\Services\SceneIntel\SceneIntelProcessor;
use App\Services\TtcAlertsFeedService;
use App\Services\TorontoFireFeedService;
use App\Services\TorontoPoliceFeedService;
use App\Models\FireIncident;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

$testRunId = 'scheduler_resilience_phase_3_data_integrity_maintenance_'.Carbon::now()->format('Y_m_d_His');
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

function assertSame(mixed $expected, mixed $actual, string $label, array $ctx = []): void
{
    if ($expected !== $actual) {
        $message = "Assertion failed: {$label}.";
        logError($message, array_merge($ctx, [
            'expected' => $expected,
            'actual' => $actual,
        ]));
        throw new RuntimeException($message);
    }

    logInfo("Assertion passed: {$label}");
}

function assertContains(string $needle, string $haystack, string $label, array $ctx = []): void
{
    if (! str_contains($haystack, $needle)) {
        $message = "Assertion failed: {$label}.";
        logError($message, array_merge($ctx, [
            'needle' => $needle,
            'haystack_excerpt' => substr($haystack, 0, 800),
        ]));
        throw new RuntimeException($message);
    }

    logInfo("Assertion passed: {$label}");
}

function assertLogContains(string $logFile, string $needle, string $label): void
{
    $contents = @file_get_contents($logFile);
    if ($contents === false) {
        throw new RuntimeException("Failed to read log file: {$logFile}");
    }

    assertContains($needle, $contents, $label);
}

/**
 * @param  callable(): void  $fn
 * @param  callable(): void  $cleanup
 */
function runWithCleanup(callable $fn, callable $cleanup): void
{
    try {
        $fn();
    } finally {
        try {
            $cleanup();
        } catch (Throwable $cleanupException) {
            logError('Cleanup failed', [
                'message' => $cleanupException->getMessage(),
                'trace' => $cleanupException->getTraceAsString(),
            ]);
        }
    }
}

$exitCode = 0;

try {
    logInfo('=== Starting Manual Test: Scheduler Resilience Phase 3 ===', [
        'app_env' => app()->environment(),
        'db_connection' => config('database.default'),
    ]);

    // Provide a clearer error if Docker/Sail isn't running.
    try {
        DB::connection()->getPdo();
    } catch (Throwable $e) {
        throw new RuntimeException(
            "Database connection failed. If you're using Sail, ensure Docker is running and execute: ./vendor/bin/sail php tests/manual/verify_scheduler_resilience_phase_3_data_integrity_maintenance.php",
            previous: $e
        );
    }

    Http::preventStrayRequests();
    Event::fake();

    $httpState = [
        'fire_xml' => null,
        'fire_status' => 200,
        'go_payload' => null,
        'go_status' => 200,
        'ttc_live_payload' => null,
        'ttc_live_status' => 200,
        'ttc_sxa_payload' => null,
        'ttc_sxa_status' => 200,
        'ttc_static_html' => null,
        'ttc_static_status' => 200,
        'police_handler' => null,
    ];

    $httpCounts = [
        'fire' => 0,
        'go' => 0,
        'ttc_live' => 0,
        'ttc_sxa' => 0,
        'ttc_static' => 0,
        'police' => 0,
    ];

    Http::fake(function (Request $request) use (&$httpState, &$httpCounts) {
        $url = $request->url();

        if (Str::is('https://www.toronto.ca/data/fire/livecad.xml*', $url)) {
            $httpCounts['fire']++;
            $xml = $httpState['fire_xml'];
            $status = (int) ($httpState['fire_status'] ?? 200);

            if (! is_string($xml)) {
                return Http::response('unhandled fire fake', 500);
            }

            return Http::response($xml, $status, ['Content-Type' => 'text/xml']);
        }

        if (Str::is('https://api.metrolinx.com/external/go/serviceupdate/en/all*', $url)) {
            $httpCounts['go']++;
            $payload = $httpState['go_payload'];
            $status = (int) ($httpState['go_status'] ?? 200);

            if (! is_array($payload)) {
                return Http::response('unhandled go fake', 500);
            }

            return Http::response($payload, $status);
        }

        if (Str::startsWith($url, 'https://alerts.ttc.ca/api/alerts/live-alerts')) {
            $httpCounts['ttc_live']++;
            $payload = $httpState['ttc_live_payload'];
            $status = (int) ($httpState['ttc_live_status'] ?? 200);

            if (! is_array($payload)) {
                return Http::response('unhandled ttc live fake', 500);
            }

            return Http::response($payload, $status);
        }

        if (Str::contains($url, '/sxa/search/results/')) {
            $httpCounts['ttc_sxa']++;
            $payload = $httpState['ttc_sxa_payload'];
            $status = (int) ($httpState['ttc_sxa_status'] ?? 200);

            if (! is_array($payload)) {
                return Http::response('unhandled ttc sxa fake', 500);
            }

            return Http::response($payload, $status);
        }

        if (Str::startsWith($url, 'https://www.ttc.ca/service-advisories/Streetcar-Service-Changes')) {
            $httpCounts['ttc_static']++;
            $html = $httpState['ttc_static_html'];
            $status = (int) ($httpState['ttc_static_status'] ?? 200);

            if (! is_string($html)) {
                return Http::response('unhandled ttc static fake', 500);
            }

            return Http::response($html, $status);
        }

        if (Str::startsWith($url, 'https://services.arcgis.com/S9th0jAJ7bqgIRjw/arcgis/rest/services/C4S_Public_NoGO/FeatureServer/0/query')) {
            $httpCounts['police']++;
            $handler = $httpState['police_handler'];

            if (! is_callable($handler)) {
                return Http::response('unhandled police fake', 500);
            }

            return $handler($request);
        }

        return Http::response('unhandled url', 500);
    });

    logInfo('Phase 1: Failed job pruning is scheduled daily');
    $schedule = app(Schedule::class);
    $events = collect($schedule->events());
    $pruneEvent = $events->first(function ($event) {
        return is_string($event->command)
            && str_contains($event->command, 'queue:prune-failed')
            && str_contains($event->command, '--hours=168');
    });
    assertTrue($pruneEvent !== null, 'queue:prune-failed --hours=168 is scheduled');
    assertSame('0 0 * * *', $pruneEvent->expression, 'queue:prune-failed runs daily at midnight');
    assertTrue((bool) $pruneEvent->withoutOverlapping, 'queue:prune-failed uses withoutOverlapping');

    logInfo('Phase 2: Feed circuit breaker opens and blocks HTTP calls (all 4 feeds)');
    config([
        'feeds.circuit_breaker.enabled' => true,
        'feeds.circuit_breaker.threshold' => 1,
        'feeds.circuit_breaker.ttl_seconds' => 60,
    ]);

    $fireService = app(TorontoFireFeedService::class);
    $policeService = app(TorontoPoliceFeedService::class);
    $goService = app(GoTransitFeedService::class);
    $ttcService = app(TtcAlertsFeedService::class);

    $httpState['fire_xml'] = '<tfs_active_incidents><update_from_db_time>2026-02-18 00:00:00</update_from_db_time></tfs_active_incidents>';
    $httpState['fire_status'] = 500;

    $httpState['go_payload'] = ['LastUpdated' => '2026-02-18T00:00:00Z'];
    $httpState['go_status'] = 500;

    $httpState['ttc_live_payload'] = ['lastUpdated' => '2026-02-18T00:00:00Z', 'routes' => []];
    $httpState['ttc_live_status'] = 500;
    $httpState['ttc_sxa_payload'] = ['Results' => []];
    $httpState['ttc_sxa_status'] = 200;
    $httpState['ttc_static_html'] = '<html><body>ok</body></html>';
    $httpState['ttc_static_status'] = 200;

    $httpState['police_handler'] = function () {
        return Http::response('upstream error', 500);
    };

    $circuitBreakerCases = [
        [
            'label' => 'toronto_fire',
            'cache_key' => 'feeds:circuit_breaker:toronto_fire',
            'count_key' => 'fire',
            'fn' => fn () => $fireService->fetch(),
        ],
        [
            'label' => 'toronto_police',
            'cache_key' => 'feeds:circuit_breaker:toronto_police',
            'count_key' => 'police',
            'fn' => fn () => $policeService->fetch(),
        ],
        [
            'label' => 'go_transit',
            'cache_key' => 'feeds:circuit_breaker:go_transit',
            'count_key' => 'go',
            'fn' => fn () => $goService->fetch(),
        ],
        [
            'label' => 'ttc_alerts',
            'cache_key' => 'feeds:circuit_breaker:ttc_alerts',
            'count_key' => 'ttc_live',
            'fn' => fn () => $ttcService->fetch(),
        ],
    ];

    foreach ($circuitBreakerCases as $case) {
        runWithCleanup(function () use ($case, &$httpCounts): void {
            Cache::forget($case['cache_key']);
            $startingCount = $httpCounts[$case['count_key']];

            try {
                ($case['fn'])();
                throw new RuntimeException("Expected {$case['label']} fetch to fail and record circuit breaker failure");
            } catch (Throwable $e) {
                assertTrue(! str_contains($e->getMessage(), 'Circuit breaker open for feed'), "{$case['label']} first failure is not circuit-breaker-open");
            }

            $afterFailureCount = $httpCounts[$case['count_key']];
            assertTrue($afterFailureCount > $startingCount, "{$case['label']} made at least one HTTP request on initial failure", [
                'starting_count' => $startingCount,
                'after_failure_count' => $afterFailureCount,
            ]);

            try {
                ($case['fn'])();
                throw new RuntimeException("Expected {$case['label']} second attempt to be blocked by circuit breaker");
            } catch (Throwable $e) {
                assertContains("Circuit breaker open for feed '{$case['label']}'", $e->getMessage(), "{$case['label']} is blocked by circuit breaker");
            }

            assertSame($afterFailureCount, $httpCounts[$case['count_key']], "{$case['label']} circuit breaker blocks HTTP calls while open");
        }, function () use ($case): void {
            Cache::forget($case['cache_key']);
        });
    }

    assertLogContains($logFile, 'Feed circuit breaker opened', 'circuit breaker logs when opened');
    assertLogContains($logFile, 'Feed circuit breaker is open; skipping fetch attempt', 'circuit breaker logs when open and skipping');

    logInfo('Phase 2b: Circuit breaker auto-recovers after TTL (fire feed)');
    runWithCleanup(function () use (&$httpState, &$httpCounts, $fireService): void {
        config([
            'feeds.circuit_breaker.enabled' => true,
            'feeds.circuit_breaker.threshold' => 1,
            'feeds.circuit_breaker.ttl_seconds' => 1,
        ]);

        Cache::forget('feeds:circuit_breaker:toronto_fire');

        $httpState['fire_status'] = 500;
        $httpState['fire_xml'] = '<tfs_active_incidents><update_from_db_time>2026-02-18 00:00:00</update_from_db_time></tfs_active_incidents>';

        $countAfterFailure = $httpCounts['fire'];
        try {
            $fireService->fetch();
            throw new RuntimeException('Expected fire fetch to fail');
        } catch (Throwable) {
        }
        assertTrue($httpCounts['fire'] > $countAfterFailure, 'fire made HTTP requests during failure');

        $countAfterOpen = $httpCounts['fire'];
        try {
            $fireService->fetch();
            throw new RuntimeException('Expected circuit breaker to be open');
        } catch (Throwable $e) {
            assertContains("Circuit breaker open for feed 'toronto_fire'", $e->getMessage(), 'fire breaker opened');
        }
        assertSame($countAfterOpen, $httpCounts['fire'], 'fire breaker blocks HTTP requests');

        sleep(2);

        $eventNum = 'MANUAL_CB_RECOVERY_FIRE_'.substr(md5((string) microtime(true)), 0, 8);
        $httpState['fire_status'] = 200;
        $httpState['fire_xml'] = <<<XML
<tfs_active_incidents>
  <update_from_db_time>2026-02-18 00:00:00</update_from_db_time>
  <event>
    <event_num>{$eventNum}</event_num>
    <event_type>FIRE</event_type>
    <prime_street>Test</prime_street>
    <cross_streets>Test</cross_streets>
    <dispatch_time>2026-02-18 00:00:00</dispatch_time>
    <alarm_lev>1</alarm_lev>
    <beat>A</beat>
    <units_disp>X</units_disp>
  </event>
</tfs_active_incidents>
XML;

        $countBeforeRecovery = $httpCounts['fire'];
        $result = $fireService->fetch();
        assertTrue(is_array($result['events'] ?? null), 'fire breaker recovery fetch returns parsed events');
        assertTrue($httpCounts['fire'] > $countBeforeRecovery, 'fire fetch resumes after TTL expiry');
    }, function (): void {
        Cache::forget('feeds:circuit_breaker:toronto_fire');
    });

    logInfo('Phase 3: Police data sanity warnings (future timestamps + coordinates outside GTA)');
    runWithCleanup(function () use (&$httpState, $policeService, $logFile): void {
        config([
            'feeds.circuit_breaker.enabled' => false,
            'feeds.allow_empty_feeds' => true,
            'feeds.sanity.future_timestamp_grace_seconds' => 0,
        ]);

        $futureMs = Carbon::now('UTC')->addHours(2)->valueOf();

        $httpState['police_handler'] = function () use ($futureMs) {
            return Http::response([
                'features' => [
                    [
                        'attributes' => [
                            'OBJECTID' => 999001,
                            'CALL_TYPE_CODE' => 'TEST',
                            'CALL_TYPE' => 'TEST CALL',
                            'DIVISION' => 'A',
                            'CROSS_STREETS' => 'TEST / TEST',
                            'LATITUDE' => 45.0,
                            'LONGITUDE' => -81.0,
                            'OCCURRENCE_TIME' => $futureMs,
                        ],
                    ],
                ],
                'exceededTransferLimit' => false,
            ], 200);
        };

        $records = $policeService->fetch();
        assertSame(1, count($records), 'police service returns one parsed record');

        assertLogContains($logFile, 'Feed record timestamp is unexpectedly in the future', 'future timestamp sanity warning logged');
        assertLogContains($logFile, 'source":"toronto_police"', 'future timestamp sanity warning includes police source');
        assertLogContains($logFile, 'Feed record coordinates fall outside GTA bounds', 'coordinate sanity warning logged');
        assertLogContains($logFile, 'source":"toronto_police"', 'coordinate sanity warning includes police source');
    }, function () use ($policeService): void {
        config([
            'feeds.circuit_breaker.enabled' => true,
            'feeds.sanity.future_timestamp_grace_seconds' => 900,
        ]);
    });

    logInfo('Phase 4: Police pagination memory safety limit triggers clear error');
    runWithCleanup(function () use (&$httpState, $policeService): void {
        config([
            'feeds.circuit_breaker.enabled' => false,
            'feeds.allow_empty_feeds' => true,
            'feeds.police.max_records' => 1,
        ]);

        $nowMs = Carbon::now('UTC')->valueOf();

        $httpState['police_handler'] = function () use ($nowMs) {
            return Http::response([
                'features' => [
                    [
                        'attributes' => [
                            'OBJECTID' => 999101,
                            'CALL_TYPE_CODE' => 'TEST',
                            'CALL_TYPE' => 'TEST CALL',
                            'DIVISION' => 'A',
                            'CROSS_STREETS' => 'TEST / TEST',
                            'LATITUDE' => 43.7,
                            'LONGITUDE' => -79.4,
                            'OCCURRENCE_TIME' => $nowMs,
                        ],
                    ],
                    [
                        'attributes' => [
                            'OBJECTID' => 999102,
                            'CALL_TYPE_CODE' => 'TEST',
                            'CALL_TYPE' => 'TEST CALL',
                            'DIVISION' => 'A',
                            'CROSS_STREETS' => 'TEST / TEST',
                            'LATITUDE' => 43.7,
                            'LONGITUDE' => -79.4,
                            'OCCURRENCE_TIME' => $nowMs,
                        ],
                    ],
                ],
                'exceededTransferLimit' => false,
            ], 200);
        };

        try {
            $policeService->fetch();
            throw new RuntimeException('Expected police fetch to fail due to max_records safety limit');
        } catch (Throwable $e) {
            assertContains('Toronto Police feed exceeded safety limit of 1 records', $e->getMessage(), 'police max_records safety limit throws');
        }
    }, function (): void {
        config([
            'feeds.circuit_breaker.enabled' => true,
            'feeds.police.max_records' => 100000,
        ]);
    });

    logInfo('Phase 5: Scene Intel failure rate monitoring triggers warning when > 50%');
    runWithCleanup(function () use (&$httpState, $testRunId, $logFile): void {
        config([
            'feeds.circuit_breaker.enabled' => false,
            'feeds.sanity.future_timestamp_grace_seconds' => 900,
        ]);

        $eventNums = [
            "MANUAL_SCENE_INTEL_1_{$testRunId}",
            "MANUAL_SCENE_INTEL_2_{$testRunId}",
            "MANUAL_SCENE_INTEL_3_{$testRunId}",
        ];

        $httpState['fire_status'] = 200;
        $httpState['fire_xml'] = <<<XML
<tfs_active_incidents>
  <update_from_db_time>2099-01-01 00:00:00</update_from_db_time>
  <event>
    <event_num>{$eventNums[0]}</event_num>
    <event_type>FIRE</event_type>
    <prime_street>Test</prime_street>
    <cross_streets>Test</cross_streets>
    <dispatch_time>2099-01-01 00:00:00</dispatch_time>
    <alarm_lev>1</alarm_lev>
    <beat>A</beat>
    <units_disp>X</units_disp>
  </event>
  <event>
    <event_num>{$eventNums[1]}</event_num>
    <event_type>FIRE</event_type>
    <prime_street>Test</prime_street>
    <cross_streets>Test</cross_streets>
    <dispatch_time>2099-01-01 00:01:00</dispatch_time>
    <alarm_lev>1</alarm_lev>
    <beat>A</beat>
    <units_disp>X</units_disp>
  </event>
  <event>
    <event_num>{$eventNums[2]}</event_num>
    <event_type>FIRE</event_type>
    <prime_street>Test</prime_street>
    <cross_streets>Test</cross_streets>
    <dispatch_time>2099-01-01 00:02:00</dispatch_time>
    <alarm_lev>1</alarm_lev>
    <beat>A</beat>
    <units_disp>X</units_disp>
  </event>
</tfs_active_incidents>
XML;

        app()->instance(SceneIntelProcessor::class, new class extends SceneIntelProcessor {
            private int $attempt = 0;

            public function processIncidentUpdate(FireIncident $incident, ?array $previousData): void
            {
                $this->attempt += 1;

                if ($this->attempt <= 2) {
                    throw new RuntimeException('simulated scene intel failure');
                }
            }
        });

        $code = Artisan::call('fire:fetch-incidents');
        $output = Artisan::output();

        assertSame(0, $code, 'fire:fetch-incidents exits SUCCESS even when Scene Intel fails per-record', ['output' => $output]);
        assertContains('Scene intel failures exceeded threshold: 2/3', $output, 'fire:fetch-incidents outputs failure rate warning');
        assertLogContains($logFile, 'Scene intel failure rate exceeded threshold', 'scene intel failure rate warning logged');
        assertLogContains($logFile, 'Feed record timestamp is unexpectedly in the future', 'future timestamp sanity warnings logged');
        assertLogContains($logFile, 'source":"toronto_fire"', 'future timestamp sanity warning includes fire source');
        assertLogContains($logFile, 'field":"updated_at"', 'future timestamp sanity warning includes updated_at field');
        assertLogContains($logFile, 'field":"dispatch_time"', 'future timestamp sanity warning includes dispatch_time field');
    }, function () use ($testRunId): void {
        DB::table('fire_incidents')->whereIn('event_num', [
            "MANUAL_SCENE_INTEL_1_{$testRunId}",
            "MANUAL_SCENE_INTEL_2_{$testRunId}",
            "MANUAL_SCENE_INTEL_3_{$testRunId}",
        ])->delete();

        // Restore the real binding for subsequent manual test runs.
        app()->forgetInstance(SceneIntelProcessor::class);
    });

    logInfo('Phase 6: TTC sentinel lastUpdated timestamp is rejected (strict ISO parsing)');
    runWithCleanup(function () use (&$httpState, $ttcService): void {
        config([
            'feeds.circuit_breaker.enabled' => false,
            'feeds.allow_empty_feeds' => true,
        ]);

        $httpState['ttc_live_status'] = 200;
        $httpState['ttc_live_payload'] = [
            'lastUpdated' => '0001-01-01T00:00:00Z',
            'routes' => [],
            'accessibility' => [],
            'siteWideCustom' => [],
            'generalCustom' => [],
            'stops' => [],
        ];

        try {
            $ttcService->fetch();
            throw new RuntimeException('Expected TTC fetch to fail due to sentinel lastUpdated');
        } catch (Throwable $e) {
            assertContains("invalid ISO8601 timestamp '0001-01-01T00:00:00Z'", $e->getMessage(), 'ttc sentinel lastUpdated throws');
        }
    }, function (): void {
        config([
            'feeds.circuit_breaker.enabled' => true,
            'feeds.allow_empty_feeds' => false,
        ]);
    });

    logInfo('Manual verification reminders (non-destructive)', [
        'schedule_list' => './vendor/bin/sail artisan schedule:list',
        'logs' => "tail -f {$logFileRelative}",
    ]);

    logInfo('=== Manual Test Completed Successfully ===');
} catch (Throwable $e) {
    $exitCode = 1;

    logError('Manual Test Failed', [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
} finally {
    echo "\nFull logs at: {$logFileRelative}\n";
}

exit($exitCode);
