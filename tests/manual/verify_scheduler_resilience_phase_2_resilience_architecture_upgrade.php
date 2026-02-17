<?php

/**
 * Manual Test: Scheduler Resilience - Phase 2 Resilience & Architecture Upgrade
 * Generated: 2026-02-17
 *
 * Purpose:
 * - Verify scheduled ingestion is job-based (not command-based) and still uses short withoutOverlapping expiry
 * - Verify fetch jobs have retry/backoff/timeout and WithoutOverlapping middleware configured for retries
 * - Verify empty feed protection preserves existing data (no mass deactivation) when ALLOW_EMPTY_FEEDS=false
 * - Verify graceful record parsing: one bad record does not halt a batch
 * - Verify police mid-pagination failure returns partial results and skips deactivation
 *
 * Run (Sail):
 * - ./vendor/bin/sail php tests/manual/verify_scheduler_resilience_phase_2_resilience_architecture_upgrade.php
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

use App\Jobs\DeliverAlertNotificationJob;
use App\Jobs\FetchFireIncidentsJob;
use App\Jobs\FetchGoTransitAlertsJob;
use App\Jobs\FetchPoliceCallsJob;
use App\Jobs\FetchTransitAlertsJob;
use App\Models\FireIncident;
use App\Models\GoTransitAlert;
use App\Models\PoliceCall;
use App\Models\TransitAlert;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

$testRunId = 'scheduler_resilience_phase_2_resilience_architecture_upgrade_'.Carbon::now()->format('Y_m_d_His');
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
            'haystack_excerpt' => substr($haystack, 0, 500),
        ]));
        throw new RuntimeException($message);
    }

    logInfo("Assertion passed: {$label}");
}

/**
 * @param  callable(): void  $fn
 */
function withTransaction(callable $fn): void
{
    DB::beginTransaction();

    try {
        $fn();
    } finally {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
    }
}

$exitCode = 0;

try {
    logInfo('=== Starting Manual Test: Scheduler Resilience Phase 2 ===', [
        'app_env' => app()->environment(),
    ]);

    Http::preventStrayRequests();
    Event::fake();

    logInfo('Phase 1: Scheduler events are job-based and short-locked');
    $schedule = app(Schedule::class);
    $events = collect($schedule->events());

    $expectedFetchEvents = [
        'fire:fetch-incidents' => 10,
        'police:fetch-calls' => 10,
        'transit:fetch-alerts' => 10,
        'go-transit:fetch-alerts' => 10,
    ];

    foreach ($expectedFetchEvents as $eventName => $expiresAtMinutes) {
        $event = $events->first(function ($event) use ($eventName) {
            return is_string($event->description) && $event->description === $eventName;
        });

        assertTrue($event !== null, "scheduled event exists for {$eventName}");
        assertTrue((bool) $event->withoutOverlapping, "{$eventName} has withoutOverlapping enabled");
        assertSame($expiresAtMinutes, (int) $event->expiresAt, "{$eventName} expiresAt is {$expiresAtMinutes} minutes", [
            'actual_expiresAt' => $event->expiresAt,
        ]);
        assertTrue(! is_string($event->command), "{$eventName} is job-based (event->command is not a string)", [
            'event_class' => is_object($event) ? get_class($event) : gettype($event),
            'event_command_type' => gettype($event->command),
        ]);
    }

    logInfo('Phase 2: Fetch job retry + overlap middleware configuration');
    $jobsToCheck = [
        FetchFireIncidentsJob::class => 'fetch-fire-incidents',
        FetchPoliceCallsJob::class => 'fetch-police-calls',
        FetchTransitAlertsJob::class => 'fetch-transit-alerts',
        FetchGoTransitAlertsJob::class => 'fetch-go-transit-alerts',
    ];

    foreach ($jobsToCheck as $jobClass => $overlapKey) {
        $job = new $jobClass;

        assertSame(3, (int) $job->tries, "{$jobClass} tries = 3");
        assertSame(30, (int) $job->backoff, "{$jobClass} backoff = 30");
        assertSame(120, (int) $job->timeout, "{$jobClass} timeout = 120");

        $middleware = $job->middleware();
        assertTrue(is_array($middleware), "{$jobClass} middleware returns array");
        assertSame(1, count($middleware), "{$jobClass} middleware count = 1");
        assertTrue($middleware[0] instanceof WithoutOverlapping, "{$jobClass} middleware[0] is WithoutOverlapping");
        assertSame($overlapKey, $middleware[0]->key, "{$jobClass} overlap key = {$overlapKey}");
        assertSame(30, $middleware[0]->releaseAfter, "{$jobClass} overlap releaseAfter = 30s");
        assertSame(600, $middleware[0]->expiresAfter, "{$jobClass} overlap expiresAfter = 600s");
    }

    logInfo('Phase 3: Notification job retry configuration');
    $notificationJob = new DeliverAlertNotificationJob(
        userId: 1,
        payload: [
            'alert_id' => 'manual-test',
            'source' => 'manual-test',
            'severity' => 'info',
            'summary' => 'manual test',
            'occurred_at' => Carbon::now()->toIso8601String(),
            'routes' => [],
        ],
    );
    assertSame(5, (int) $notificationJob->tries, 'DeliverAlertNotificationJob tries = 5');
    assertSame(10, (int) $notificationJob->backoff, 'DeliverAlertNotificationJob backoff = 10');

    logInfo('Phase 4: Empty feed protection (preserve existing actives when empty feeds not allowed)');
    withTransaction(function (): void {
        config(['feeds.allow_empty_feeds' => false]);

        $incident = FireIncident::factory()->create(['is_active' => true]);
        $xml = <<<'XML'
<tfs_active_incidents>
  <update_from_db_time>2026-02-17 00:00:00</update_from_db_time>
</tfs_active_incidents>
XML;
        Http::fake([
            'https://www.toronto.ca/data/fire/livecad.xml*' => Http::response($xml, 200, ['Content-Type' => 'text/xml']),
        ]);

        $code = Artisan::call('fire:fetch-incidents');
        assertSame(1, $code, 'fire:fetch-incidents exits with FAILURE on empty feed', ['output' => Artisan::output()]);
        assertTrue((bool) $incident->refresh()->is_active, 'fire:fetch-incidents preserves existing active incidents');
    });

    withTransaction(function (): void {
        config(['feeds.allow_empty_feeds' => false]);

        $call = PoliceCall::factory()->create(['is_active' => true]);
        Http::fake([
            '*' => Http::response([
                'features' => [],
                'exceededTransferLimit' => false,
            ], 200),
        ]);

        $code = Artisan::call('police:fetch-calls');
        assertSame(1, $code, 'police:fetch-calls exits with FAILURE on empty feed', ['output' => Artisan::output()]);
        assertTrue((bool) $call->refresh()->is_active, 'police:fetch-calls preserves existing active calls');
    });

    withTransaction(function (): void {
        config(['feeds.allow_empty_feeds' => false]);

        $alert = GoTransitAlert::factory()->create(['is_active' => true]);
        Http::fake([
            'https://api.metrolinx.com/external/go/serviceupdate/en/all*' => Http::response([
                'LastUpdated' => '2026-02-17T00:00:00Z',
                'Trains' => ['Train' => []],
                'Buses' => ['Bus' => []],
                'Stations' => ['Station' => []],
            ], 200),
        ]);

        $code = Artisan::call('go-transit:fetch-alerts');
        assertSame(1, $code, 'go-transit:fetch-alerts exits with FAILURE on empty feed', ['output' => Artisan::output()]);
        assertTrue((bool) $alert->refresh()->is_active, 'go-transit:fetch-alerts preserves existing active alerts');
    });

    withTransaction(function (): void {
        config(['feeds.allow_empty_feeds' => false]);

        $alert = TransitAlert::factory()->create(['is_active' => true]);
        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            $url = $request->url();

            if (str_starts_with($url, 'https://alerts.ttc.ca/api/alerts/live-alerts')) {
                return Http::response([
                    'lastUpdated' => '2026-02-17T00:00:00Z',
                    'routes' => [],
                    'accessibility' => [],
                    'siteWideCustom' => [],
                    'generalCustom' => [],
                    'stops' => [],
                    'status' => 'success',
                ], 200);
            }

            if (str_contains($url, '/sxa/search/results/')) {
                return Http::response(['Results' => []], 200);
            }

            if (str_starts_with($url, 'https://www.ttc.ca/service-advisories/Streetcar-Service-Changes')) {
                return Http::response('<html></html>', 200);
            }

            return Http::response('unexpected url', 500);
        });

        $code = Artisan::call('transit:fetch-alerts');
        assertSame(1, $code, 'transit:fetch-alerts exits with FAILURE on empty feed', ['output' => Artisan::output()]);
        assertTrue((bool) $alert->refresh()->is_active, 'transit:fetch-alerts preserves existing active alerts');
    });

    logInfo('Phase 5: Graceful record parsing (single bad record does not halt the batch)');
    withTransaction(function (): void {
        config(['feeds.allow_empty_feeds' => true]);

        $xml = <<<'XML'
<tfs_active_incidents>
  <update_from_db_time>2026-02-17 00:00:00</update_from_db_time>
  <event>
    <event_num>E_BAD</event_num>
    <event_type>Fire</event_type>
    <prime_street>Street 1</prime_street>
    <dispatch_time>not-a-timestamp</dispatch_time>
    <alarm_lev>1</alarm_lev>
  </event>
  <event>
    <event_num>E_GOOD</event_num>
    <event_type>Medical</event_type>
    <prime_street>Street 2</prime_street>
    <dispatch_time>2026-02-17T00:05:00</dispatch_time>
    <alarm_lev>0</alarm_lev>
  </event>
</tfs_active_incidents>
XML;

        Http::fake([
            'https://www.toronto.ca/data/fire/livecad.xml*' => Http::response($xml, 200, ['Content-Type' => 'text/xml']),
        ]);

        $code = Artisan::call('fire:fetch-incidents');
        $output = Artisan::output();

        assertSame(0, $code, 'fire:fetch-incidents exits SUCCESS when some records are malformed', ['output' => $output]);
        assertTrue(FireIncident::query()->where('event_num', 'E_GOOD')->exists(), 'fire:fetch-incidents persists the good record');
        assertTrue(! FireIncident::query()->where('event_num', 'E_BAD')->exists(), 'fire:fetch-incidents skips the bad record');
        assertContains('Skipping event E_BAD due to dispatch_time parse failure', $output, 'fire:fetch-incidents outputs a skip warning');
    });

    withTransaction(function (): void {
        config(['feeds.allow_empty_feeds' => true]);

        $codeValue = 'L1';
        $badSubject = 'Bad timestamp';
        $goodSubject = 'Good timestamp';
        $subCategory = null;

        $badExternalId = 'notif:'.$codeValue.':'.$subCategory.':'.md5($badSubject);
        $goodExternalId = 'notif:'.$codeValue.':'.$subCategory.':'.md5($goodSubject);

        Http::fake([
            'https://api.metrolinx.com/external/go/serviceupdate/en/all*' => Http::response([
                'LastUpdated' => '2026-02-17T00:00:00Z',
                'Trains' => [
                    'Train' => [
                        [
                            'Code' => $codeValue,
                            'Name' => 'Test Line',
                            'Notifications' => [
                                [
                                    'MessageSubject' => $badSubject,
                                    'MessageBody' => null,
                                    'SubCategory' => '',
                                    'Status' => 'Active',
                                    'PostedDateTime' => 'not-a-timestamp',
                                ],
                                [
                                    'MessageSubject' => $goodSubject,
                                    'MessageBody' => null,
                                    'SubCategory' => '',
                                    'Status' => 'Active',
                                    'PostedDateTime' => '2026-02-17T00:01:00Z',
                                ],
                            ],
                        ],
                    ],
                ],
                'Buses' => ['Bus' => []],
                'Stations' => ['Station' => []],
            ], 200),
        ]);

        $code = Artisan::call('go-transit:fetch-alerts');
        $output = Artisan::output();

        assertSame(0, $code, 'go-transit:fetch-alerts exits SUCCESS when some records are malformed', ['output' => $output]);
        assertTrue(GoTransitAlert::query()->where('external_id', $goodExternalId)->exists(), 'go-transit:fetch-alerts persists the good record');
        assertTrue(! GoTransitAlert::query()->where('external_id', $badExternalId)->exists(), 'go-transit:fetch-alerts skips the bad record');
        assertContains("Skipping alert {$badExternalId} due to posted_at parse failure", $output, 'go-transit:fetch-alerts outputs a skip warning');
    });

    logInfo('Phase 6: Police partial pagination skips deactivation');
    withTransaction(function (): void {
        config(['feeds.allow_empty_feeds' => false]);

        $inFeedId = 123;
        $staleId = 999;

        $stale = PoliceCall::factory()->create([
            'object_id' => $staleId,
            'is_active' => true,
        ]);

        Http::fake(function (\Illuminate\Http\Client\Request $request) use ($inFeedId) {
            $data = $request->data();
            $resultOffset = (int) ($data['resultOffset'] ?? 0);

            if ($resultOffset === 0) {
                return Http::response([
                    'features' => [
                        [
                            'attributes' => [
                                'OBJECTID' => $inFeedId,
                                'CALL_TYPE_CODE' => 'TEST',
                                'CALL_TYPE' => 'Test Call',
                                'DIVISION' => 'D1',
                                'CROSS_STREETS' => 'A / B',
                                'LATITUDE' => 43.65,
                                'LONGITUDE' => -79.38,
                                'OCCURRENCE_TIME' => 1708128000000,
                            ],
                        ],
                    ],
                    'exceededTransferLimit' => true,
                ], 200);
            }

            if ($resultOffset === 1000) {
                return Http::response('upstream error', 500);
            }

            return Http::response('unexpected pagination offset', 500);
        });

        $code = Artisan::call('police:fetch-calls');
        $output = Artisan::output();

        assertSame(0, $code, 'police:fetch-calls exits SUCCESS on partial pagination', ['output' => $output]);
        assertContains('Police feed pagination was partial; stale call deactivation will be skipped for this run.', $output, 'police:fetch-calls warns about partial pagination');
        assertTrue((bool) $stale->refresh()->is_active, 'police:fetch-calls does not deactivate stale calls when partial');
    });

    logInfo('Manual verification reminders (non-destructive)', [
        'schedule_list' => './vendor/bin/sail artisan schedule:list',
        'scheduler_heartbeat' => './vendor/bin/sail artisan scheduler:run-and-log',
        'queue_worker' => './vendor/bin/sail artisan queue:work --stop-when-empty',
        'logs' => 'tail -f storage/logs/laravel.log',
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
