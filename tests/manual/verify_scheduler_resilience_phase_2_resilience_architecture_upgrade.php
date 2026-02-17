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
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
    logInfo('=== Starting Manual Test: Scheduler Resilience Phase 2 ===', [
        'app_env' => app()->environment(),
    ]);

    Event::fake();

    Http::preventStrayRequests();

    $httpState = [
        'fire_xml' => null,
        'go_payload' => null,
        'ttc_live_payload' => null,
        'ttc_sxa_payload' => null,
        'ttc_static_html' => null,
        'police_handler' => null,
    ];

    Http::fake(function (Request $request) use (&$httpState) {
        $url = $request->url();

        if (Str::is('https://www.toronto.ca/data/fire/livecad.xml*', $url)) {
            $xml = $httpState['fire_xml'];

            if (! is_string($xml)) {
                return Http::response('unhandled fire fake', 500);
            }

            return Http::response($xml, 200, ['Content-Type' => 'text/xml']);
        }

        if (Str::is('https://api.metrolinx.com/external/go/serviceupdate/en/all*', $url)) {
            $payload = $httpState['go_payload'];

            if (! is_array($payload)) {
                return Http::response('unhandled go fake', 500);
            }

            return Http::response($payload, 200);
        }

        if (Str::startsWith($url, 'https://alerts.ttc.ca/api/alerts/live-alerts')) {
            $payload = $httpState['ttc_live_payload'];

            if (! is_array($payload)) {
                return Http::response('unhandled ttc live fake', 500);
            }

            return Http::response($payload, 200);
        }

        if (Str::contains($url, '/sxa/search/results/')) {
            $payload = $httpState['ttc_sxa_payload'];

            if (! is_array($payload)) {
                return Http::response('unhandled ttc sxa fake', 500);
            }

            return Http::response($payload, 200);
        }

        if (Str::startsWith($url, 'https://www.ttc.ca/service-advisories/Streetcar-Service-Changes')) {
            $html = $httpState['ttc_static_html'];

            if (! is_string($html)) {
                return Http::response('unhandled ttc static fake', 500);
            }

            return Http::response($html, 200);
        }

        if (Str::startsWith($url, 'https://services.arcgis.com/S9th0jAJ7bqgIRjw/arcgis/rest/services/C4S_Public_NoGO/FeatureServer/0/query')) {
            $handler = $httpState['police_handler'];

            if (! is_callable($handler)) {
                return Http::response('unhandled police fake', 500);
            }

            return $handler($request);
        }

        return Http::response('unhandled url', 500);
    });

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
    runWithCleanup(function () use ($testRunId, &$httpState): void {
        config(['feeds.allow_empty_feeds' => false]);

        $incident = FireIncident::factory()->create([
            'event_num' => "MANUAL_FIRE_EMPTY_{$testRunId}",
            'is_active' => true,
        ]);
        $xml = <<<'XML'
<tfs_active_incidents>
  <update_from_db_time>2026-02-17 00:00:00</update_from_db_time>
</tfs_active_incidents>
XML;
        $httpState['fire_xml'] = $xml;

        $code = Artisan::call('fire:fetch-incidents');
        assertSame(1, $code, 'fire:fetch-incidents exits with FAILURE on empty feed', ['output' => Artisan::output()]);
        assertTrue((bool) $incident->refresh()->is_active, 'fire:fetch-incidents preserves existing active incidents');
    }, function () use ($testRunId): void {
        FireIncident::query()->where('event_num', "MANUAL_FIRE_EMPTY_{$testRunId}")->delete();
    });

    runWithCleanup(function () use ($testRunId, &$httpState): void {
        config(['feeds.allow_empty_feeds' => false]);

        $objectId = 900000000 + (abs(crc32("{$testRunId}:police-empty")) % 1000000);
        $call = PoliceCall::factory()->create([
            'object_id' => $objectId,
            'is_active' => true,
        ]);
        $httpState['police_handler'] = function (Request $request) {
            return Http::response([
                'features' => [],
                'exceededTransferLimit' => false,
            ], 200);
        };

        $code = Artisan::call('police:fetch-calls');
        assertSame(1, $code, 'police:fetch-calls exits with FAILURE on empty feed', ['output' => Artisan::output()]);
        assertTrue((bool) $call->refresh()->is_active, 'police:fetch-calls preserves existing active calls');
    }, function () use ($testRunId): void {
        $objectId = 900000000 + (abs(crc32("{$testRunId}:police-empty")) % 1000000);
        PoliceCall::query()->where('object_id', $objectId)->delete();
    });

    runWithCleanup(function () use ($testRunId, &$httpState): void {
        config(['feeds.allow_empty_feeds' => false]);

        $alert = GoTransitAlert::factory()->create([
            'external_id' => "MANUAL_GO_EMPTY_{$testRunId}",
            'is_active' => true,
        ]);
        $httpState['go_payload'] = [
            'LastUpdated' => '2026-02-17T00:00:00Z',
            'Trains' => ['Train' => []],
            'Buses' => ['Bus' => []],
            'Stations' => ['Station' => []],
        ];

        $code = Artisan::call('go-transit:fetch-alerts');
        assertSame(1, $code, 'go-transit:fetch-alerts exits with FAILURE on empty feed', ['output' => Artisan::output()]);
        assertTrue((bool) $alert->refresh()->is_active, 'go-transit:fetch-alerts preserves existing active alerts');
    }, function () use ($testRunId): void {
        GoTransitAlert::query()->where('external_id', "MANUAL_GO_EMPTY_{$testRunId}")->delete();
    });

    runWithCleanup(function () use ($testRunId, &$httpState): void {
        config(['feeds.allow_empty_feeds' => false]);

        $alert = TransitAlert::factory()->create([
            'external_id' => "MANUAL_TTC_EMPTY_{$testRunId}",
            'is_active' => true,
        ]);
        $httpState['ttc_live_payload'] = [
            'lastUpdated' => '2026-02-17T00:00:00Z',
            'routes' => [],
            'accessibility' => [],
            'siteWideCustom' => [],
            'generalCustom' => [],
            'stops' => [],
            'status' => 'success',
        ];
        $httpState['ttc_sxa_payload'] = ['Results' => []];
        $httpState['ttc_static_html'] = '<html></html>';

        $code = Artisan::call('transit:fetch-alerts');
        assertSame(1, $code, 'transit:fetch-alerts exits with FAILURE on empty feed', ['output' => Artisan::output()]);
        assertTrue((bool) $alert->refresh()->is_active, 'transit:fetch-alerts preserves existing active alerts');
    }, function () use ($testRunId): void {
        TransitAlert::query()->where('external_id', "MANUAL_TTC_EMPTY_{$testRunId}")->delete();
    });

    logInfo('Phase 5: Graceful record parsing (single bad record does not halt the batch)');
    runWithCleanup(function () use ($testRunId, &$httpState): void {
        config(['feeds.allow_empty_feeds' => true]);

        $badEventNum = "MANUAL_FIRE_BAD_{$testRunId}";
        $goodEventNum = "MANUAL_FIRE_GOOD_{$testRunId}";

        $xml = <<<'XML'
<tfs_active_incidents>
  <update_from_db_time>2026-02-17 00:00:00</update_from_db_time>
  <event>
    <event_num>%s</event_num>
    <event_type>Fire</event_type>
    <prime_street>Street 1</prime_street>
    <dispatch_time>not-a-timestamp</dispatch_time>
    <alarm_lev>1</alarm_lev>
  </event>
  <event>
    <event_num>%s</event_num>
    <event_type>Medical</event_type>
    <prime_street>Street 2</prime_street>
    <dispatch_time>2026-02-17T00:05:00</dispatch_time>
    <alarm_lev>0</alarm_lev>
  </event>
</tfs_active_incidents>
XML;
        $xml = sprintf($xml, $badEventNum, $goodEventNum);

        $httpState['fire_xml'] = $xml;

        $code = Artisan::call('fire:fetch-incidents');
        $output = Artisan::output();

        assertSame(0, $code, 'fire:fetch-incidents exits SUCCESS when some records are malformed', ['output' => $output]);
        assertTrue(FireIncident::query()->where('event_num', $goodEventNum)->exists(), 'fire:fetch-incidents persists the good record', [
            'good_event_num' => $goodEventNum,
            'output' => $output,
        ]);
        assertTrue(! FireIncident::query()->where('event_num', $badEventNum)->exists(), 'fire:fetch-incidents skips the bad record', [
            'bad_event_num' => $badEventNum,
            'output' => $output,
        ]);
        assertContains("Skipping event {$badEventNum} due to dispatch_time parse failure", $output, 'fire:fetch-incidents outputs a skip warning');
    }, function () use ($testRunId): void {
        FireIncident::query()
            ->whereIn('event_num', [
                "MANUAL_FIRE_BAD_{$testRunId}",
                "MANUAL_FIRE_GOOD_{$testRunId}",
            ])
            ->delete();
    });

    runWithCleanup(function () use ($testRunId, &$httpState): void {
        config(['feeds.allow_empty_feeds' => true]);

        $codeValue = "MANUAL_{$testRunId}";
        $badSubject = 'Bad timestamp';
        $goodSubject = 'Good timestamp';
        $subCategory = null;

        $badExternalId = 'notif:'.$codeValue.':'.$subCategory.':'.md5($badSubject);
        $goodExternalId = 'notif:'.$codeValue.':'.$subCategory.':'.md5($goodSubject);

        $httpState['go_payload'] = [
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
        ];

        $code = Artisan::call('go-transit:fetch-alerts');
        $output = Artisan::output();

        assertSame(0, $code, 'go-transit:fetch-alerts exits SUCCESS when some records are malformed', ['output' => $output]);
        assertTrue(GoTransitAlert::query()->where('external_id', $goodExternalId)->exists(), 'go-transit:fetch-alerts persists the good record');
        assertTrue(! GoTransitAlert::query()->where('external_id', $badExternalId)->exists(), 'go-transit:fetch-alerts skips the bad record');
        assertContains("Skipping alert {$badExternalId} due to posted_at parse failure", $output, 'go-transit:fetch-alerts outputs a skip warning');
    }, function () use ($testRunId): void {
        GoTransitAlert::query()
            ->where('external_id', 'like', 'notif:MANUAL_'.$testRunId.':%')
            ->delete();
    });

    logInfo('Phase 6: Police partial pagination skips deactivation');
    runWithCleanup(function () use ($testRunId, &$httpState): void {
        config(['feeds.allow_empty_feeds' => false]);

        $base = 910000000 + (abs(crc32("{$testRunId}:police-partial")) % 1000000);
        $inFeedId = $base;
        $staleId = $base + 1;

        $stale = PoliceCall::factory()->create([
            'object_id' => $staleId,
            'is_active' => true,
        ]);

        $httpState['police_handler'] = function (Request $request) use ($inFeedId) {
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
        };

        $code = Artisan::call('police:fetch-calls');
        $output = Artisan::output();

        assertSame(0, $code, 'police:fetch-calls exits SUCCESS on partial pagination', ['output' => $output]);
        assertContains('Police feed pagination was partial; stale call deactivation will be skipped for this run.', $output, 'police:fetch-calls warns about partial pagination');
        assertTrue((bool) $stale->refresh()->is_active, 'police:fetch-calls does not deactivate stale calls when partial');
    }, function () use ($testRunId): void {
        $base = 910000000 + (abs(crc32("{$testRunId}:police-partial")) % 1000000);
        PoliceCall::query()->whereIn('object_id', [$base, $base + 1])->delete();
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
