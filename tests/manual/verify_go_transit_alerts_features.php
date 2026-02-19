<?php

/**
 * Manual Test: GO/Transit Last 8 Feature Commits Verification
 * Generated: 2026-02-06
 * Purpose: End-to-end manual verification for the most recent 8 feature commits:
 *
 * - 7742976 feat(frontend): add GO Transit mapping, severity, and styling
 * - fd5099b feat(go-transit): wire provider into unified alerts system
 * - 3887e6d feat(go-transit): add feed service, fetch command, job, and schedule
 * - 9335820 feat(database): add go_transit_alerts table, model, and factory
 * - a7cf9cc feat(enums): add GoTransit case to AlertSource
 * - be84fa4 feat(transit): complete phase 4 behavior
 * - cd92da9 feat(transit): wire phase 3 backend integration
 * - cf700eb feat(transit): implement phase 2 feed ingestion
 *
 * Optional command gates:
 * - RUN_FRONTEND_GATES=1 to execute frontend mapping tests.
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
// Use a deterministic testing-only fallback so middleware/session bootstrapping
// does not fail when making Inertia requests through the HTTP kernel.
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

use App\Enums\AlertSource;
use App\Http\Middleware\HandleInertiaRequests;
use App\Jobs\FetchGoTransitAlertsJob;
use App\Jobs\FetchTransitAlertsJob;
use App\Models\FireIncident;
use App\Models\GoTransitAlert;
use App\Models\PoliceCall;
use App\Models\TransitAlert;
use App\Services\Alerts\DTOs\UnifiedAlertsCriteria;
use App\Services\Alerts\Mappers\UnifiedAlertMapper;
use App\Services\Alerts\Providers\GoTransitAlertSelectProvider;
use App\Services\Alerts\Providers\TransitAlertSelectProvider;
use App\Services\Alerts\UnifiedAlertsQuery;
use App\Services\GoTransitFeedService;
use App\Services\TtcAlertsFeedService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

$testRunId = 'go_transit_last_8_feature_commits_'.Carbon::now()->format('Y_m_d_His');
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

if (! @chmod($logFile, 0664)) {
    fwrite(STDERR, "Warning: Failed to set permissions on log file: {$logFile}\n");
}

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

function assertArrayHasKey(string $key, array $array, string $label): void
{
    assertTrue(array_key_exists($key, $array), $label, ['key' => $key, 'keys' => array_keys($array)]);
}

/**
 * @param  array<string, mixed>  $responses
 */
function fakeHttpResponses(array $responses): void
{
    // In long-running manual scripts, consecutive Http::fake() calls are cumulative.
    // Reset facade root before each phase to avoid stale stubs leaking forward.
    Http::swap(new HttpFactory);
    Http::fake($responses);
}

/**
 * @param  list<array<string, mixed>>  $alerts
 */
function buildTransitFeedStub(CarbonInterface $updatedAt, array $alerts): TtcAlertsFeedService
{
    return new class($updatedAt, $alerts) extends TtcAlertsFeedService
    {
        /**
         * @param  list<array<string, mixed>>  $alerts
         */
        public function __construct(
            private readonly CarbonInterface $updatedAt,
            private readonly array $alerts,
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

/**
 * @param  list<array<string, mixed>>  $alerts
 */
function buildGoFeedStub(string $updatedAt, array $alerts): GoTransitFeedService
{
    return new class($updatedAt, $alerts) extends GoTransitFeedService
    {
        /**
         * @param  list<array<string, mixed>>  $alerts
         */
        public function __construct(
            private readonly string $updatedAt,
            private readonly array $alerts,
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

function assertScheduledCommand(string $commandName, string $expectedExpression, bool $expectedWithoutOverlapping): void
{
    $schedule = app(Schedule::class);
    $event = collect($schedule->events())
        ->first(fn ($candidate) => is_string($candidate->command) && str_contains($candidate->command, $commandName));

    assertTrue($event !== null, "{$commandName} exists in scheduler");
    assertEqual($event->expression, $expectedExpression, "{$commandName} schedule expression");
    assertTrue($event->withoutOverlapping === $expectedWithoutOverlapping, "{$commandName} withoutOverlapping flag");
}

/**
 * @return array<string, mixed>
 */
function makeInertiaPayload(array $query = []): array
{
    $httpKernel = app(HttpKernel::class);
    $inertiaMiddleware = app(HandleInertiaRequests::class);

    $request = Request::create('/', 'GET', $query, [], [], [
        'HTTP_X_INERTIA' => 'true',
        'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        'HTTP_ACCEPT' => 'application/json',
    ]);

    $version = $inertiaMiddleware->version($request);
    if (is_string($version) && $version !== '') {
        $request->headers->set('X-Inertia-Version', $version);
    }

    $response = $httpKernel->handle($request);

    if (method_exists($httpKernel, 'terminate')) {
        $httpKernel->terminate($request, $response);
    }

    if ($response->getStatusCode() === 409) {
        $location = $response->headers->get('X-Inertia-Location') ?? $response->headers->get('Location');
        $suffix = $location ? " Location: {$location}" : '';

        throw new RuntimeException("Inertia asset version mismatch (409).{$suffix}");
    }

    $payload = json_decode($response->getContent() ?: 'null', true);
    if (! is_array($payload)) {
        throw new RuntimeException('Expected Inertia JSON payload but got non-JSON response.');
    }

    return $payload;
}

function runCommandGate(string $label, string $command): void
{
    logInfo("Running command gate: {$label}", ['command' => $command]);
    $process = new Process(['bash', '-lc', $command], base_path());
    $process->setTimeout(null);

    $buffer = '';

    $process->run(function (string $type, string $output) use (&$buffer): void {
        $buffer .= $output;
        echo $output;
    });

    if (! $process->isSuccessful()) {
        logError("Command gate failed: {$label}", [
            'exit_code' => $process->getExitCode(),
            'output_tail' => mb_substr($buffer, -5000),
        ]);

        throw new RuntimeException("Command gate failed: {$label}");
    }

    logInfo("Command gate passed: {$label}", ['exit_code' => $process->getExitCode()]);
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

    $requiredTables = ['fire_incidents', 'police_calls', 'transit_alerts', 'go_transit_alerts'];
    foreach ($requiredTables as $table) {
        if (! Schema::hasTable($table)) {
            logInfo("{$table} missing; running migrations for testing database");
            Artisan::call('migrate', ['--force' => true]);
            logInfo('Migration output', ['output' => trim(Artisan::output())]);
            break;
        }
    }

    DB::beginTransaction();
    $txStarted = true;

    logInfo('=== Starting Manual Test: GO/Transit Last 8 Feature Commits ===');

    logInfo('Phase 1: Verify enum, schema, model, and factory foundations');

    assertEqual(AlertSource::values(), ['fire', 'police', 'transit', 'go_transit'], 'AlertSource::values ordering');
    assertTrue(AlertSource::isValid('go_transit'), 'AlertSource::isValid accepts go_transit');
    assertTrue(! AlertSource::isValid(''), 'AlertSource::isValid rejects empty string');
    assertTrue(! AlertSource::isValid(null), 'AlertSource::isValid rejects null');

    assertTrue(Schema::hasTable('go_transit_alerts'), 'go_transit_alerts table exists');
    foreach ([
        'external_id',
        'alert_type',
        'service_mode',
        'corridor_or_route',
        'message_subject',
        'posted_at',
        'is_active',
        'feed_updated_at',
    ] as $column) {
        assertTrue(Schema::hasColumn('go_transit_alerts', $column), "go_transit_alerts has {$column} column");
    }

    GoTransitAlert::query()->delete();

    GoTransitAlert::factory()->notification()->create([
        'external_id' => 'notif:PH1:TDELAY:active',
        'is_active' => true,
    ]);
    GoTransitAlert::factory()->notification()->inactive()->create([
        'external_id' => 'notif:PH1:TDELAY:inactive',
    ]);

    assertEqual((int) GoTransitAlert::query()->count(), 2, 'GoTransitAlert factory creates records');
    assertEqual((int) GoTransitAlert::active()->count(), 1, 'GoTransitAlert::active scope filters correctly');

    logInfo('Phase 2: Verify transit ingestion command, job wrapper, and scheduler entry');

    TransitAlert::query()->delete();
    TransitAlert::factory()->create([
        'external_id' => 'api:phase2-stale',
        'source_feed' => 'live-api',
        'title' => 'Stale transit alert',
        'is_active' => true,
    ]);

    $transitFeedUpdatedAt = CarbonImmutable::parse('2026-02-06 11:59:00');
    app()->instance(TtcAlertsFeedService::class, buildTransitFeedStub($transitFeedUpdatedAt, [
        [
            'external_id' => 'api:phase2-keep',
            'source_feed' => 'live-api',
            'alert_type' => 'Planned',
            'route_type' => 'Subway',
            'route' => '1',
            'title' => 'Line 1 delay',
            'description' => 'Expect reduced service.',
            'severity' => 'Critical',
            'effect' => 'REDUCED_SERVICE',
            'cause' => 'OTHER_CAUSE',
            'active_period_start' => CarbonImmutable::parse('2026-02-06 11:55:00'),
            'active_period_end' => null,
            'direction' => 'Both Ways',
            'stop_start' => 'Finch',
            'stop_end' => 'Eglinton',
            'url' => 'https://www.ttc.ca/service-alerts',
        ],
        [
            'external_id' => 'sxa:phase2-new',
            'source_feed' => 'sxa',
            'alert_type' => 'Planned',
            'route_type' => 'Streetcar',
            'route' => '504',
            'title' => '504 Temporary service change',
            'description' => 'Streetcars replaced by buses.',
            'severity' => 'Minor',
            'effect' => 'DETOUR',
            'cause' => 'MAINTENANCE',
            'active_period_start' => CarbonImmutable::parse('2026-02-06 11:30:00'),
            'active_period_end' => CarbonImmutable::parse('2026-02-06 14:00:00'),
            'direction' => null,
            'stop_start' => null,
            'stop_end' => null,
            'url' => 'https://www.ttc.ca/service-advisories/Streetcar-Service-Changes',
        ],
    ]));

    $transitCommandExit = Artisan::call('transit:fetch-alerts');
    $transitCommandOutput = Artisan::output();

    assertEqual($transitCommandExit, 0, 'transit:fetch-alerts exits successfully');
    assertContains($transitCommandOutput, 'Done. 2 active alerts synced, 1 marked inactive.', 'transit command summary output');
    assertEqual((int) TransitAlert::query()->where('external_id', 'api:phase2-stale')->value('is_active'), 0, 'transit stale alert deactivated');
    assertEqual((int) TransitAlert::query()->where('external_id', 'api:phase2-keep')->value('is_active'), 1, 'transit keep alert remains active');
    assertEqual((int) TransitAlert::query()->where('external_id', 'sxa:phase2-new')->value('is_active'), 1, 'transit new alert created active');

    TransitAlert::query()->delete();
    app()->instance(TtcAlertsFeedService::class, buildTransitFeedStub($transitFeedUpdatedAt, [
        [
            'external_id' => 'api:phase2-job',
            'source_feed' => 'live-api',
            'alert_type' => 'Planned',
            'route_type' => 'Bus',
            'route' => '52',
            'title' => 'Bus route detour',
            'description' => 'Detour in effect.',
            'severity' => 'Minor',
            'effect' => 'DETOUR',
            'cause' => 'OTHER_CAUSE',
            'active_period_start' => CarbonImmutable::parse('2026-02-06 11:50:00'),
            'active_period_end' => null,
            'direction' => 'Eastbound',
            'stop_start' => 'Lawrence',
            'stop_end' => 'Yonge',
            'url' => 'https://www.ttc.ca/service-alerts',
        ],
    ]));

    (new FetchTransitAlertsJob)->handle();

    assertEqual((int) TransitAlert::query()->where('external_id', 'api:phase2-job')->count(), 1, 'FetchTransitAlertsJob dispatches transit command');
    assertScheduledCommand('transit:fetch-alerts', '*/5 * * * *', true);

    logInfo('Phase 3: Verify GO feed service parsing, command sync, job wrapper, and scheduler entry');

    fakeHttpResponses([
        'https://api.metrolinx.com/external/go/serviceupdate/en/all*' => Http::response([
            'LastUpdated' => '2026-02-06T17:10:00-05:00',
            'Trains' => [
                'Train' => [[
                    'Code' => 'LW',
                    'Name' => 'Lakeshore West',
                    'LineColour' => '#8B4513',
                    'Notifications' => ['Notification' => [[
                        'SubCategory' => 'TDELAY',
                        'MessageSubject' => 'Lakeshore West delays',
                        'MessageBody' => '<p>Expect <b>15 min</b> delays</p>',
                        'PostedDateTime' => '02/06/2026 17:00:00',
                        'Status' => 'INIT',
                    ]]],
                    'SaagNotifications' => ['SaagNotification' => [[
                        'Direction' => 'EASTBOUND',
                        'HeadSign' => 'Union Station',
                        'DelayDuration' => '00:10:00',
                        'DepartureTimeDisplay' => '5:20 PM',
                        'ArrivalTimeTimeDisplay' => '6:05 PM',
                        'Status' => 'Moving',
                        'TripNumbers' => ['4521'],
                        'PostedDateTime' => '2026-02-06 17:05:00',
                    ]]],
                ]],
            ],
            'Buses' => ['Bus' => []],
            'Stations' => ['Station' => []],
        ], 200),
    ]);

    $goFeedResult = app(GoTransitFeedService::class)->fetch();
    assertEqual($goFeedResult['updated_at'], '2026-02-06T17:10:00-05:00', 'go feed updated_at');
    assertEqual(count($goFeedResult['alerts']), 2, 'go feed parsed alert count');

    $goNotification = collect($goFeedResult['alerts'])->firstWhere('alert_type', 'notification');
    assertTrue(is_array($goNotification), 'go notification parsed');
    assertEqual($goNotification['service_mode'] ?? null, 'GO Train', 'go notification service_mode');
    assertEqual($goNotification['sub_category'] ?? null, 'TDELAY', 'go notification sub_category');
    assertEqual($goNotification['message_body'] ?? null, 'Expect 15 min delays', 'go notification strips html from body');

    $goSaag = collect($goFeedResult['alerts'])->firstWhere('alert_type', 'saag');
    assertTrue(is_array($goSaag), 'go saag parsed');
    assertEqual($goSaag['external_id'] ?? null, 'saag:LW:4521', 'go saag external_id');
    assertEqual($goSaag['direction'] ?? null, 'EASTBOUND', 'go saag direction');
    assertEqual($goSaag['delay_duration'] ?? null, '00:10:00', 'go saag delay duration');

    GoTransitAlert::query()->delete();

    GoTransitAlert::factory()->create([
        'external_id' => 'notif:LW:TDELAY:stale',
        'is_active' => true,
    ]);

    app()->instance(GoTransitFeedService::class, buildGoFeedStub('2026-02-06T17:10:00-05:00', [
        [
            'external_id' => 'notif:LW:TDELAY:keep',
            'alert_type' => 'notification',
            'service_mode' => 'GO Train',
            'corridor_or_route' => 'Lakeshore West',
            'corridor_code' => 'LW',
            'sub_category' => 'TDELAY',
            'message_subject' => 'Lakeshore West delays',
            'message_body' => 'Expect 15 min delays',
            'direction' => null,
            'trip_number' => null,
            'delay_duration' => null,
            'status' => 'INIT',
            'line_colour' => '#8B4513',
            'posted_at' => '02/06/2026 17:00:00',
        ],
        [
            'external_id' => 'saag:LW:4521',
            'alert_type' => 'saag',
            'service_mode' => 'GO Train',
            'corridor_or_route' => 'Lakeshore West',
            'corridor_code' => 'LW',
            'sub_category' => null,
            'message_subject' => 'Lakeshore West - Union Station delayed (00:10:00)',
            'message_body' => 'Departure: 5:20 PM. Arrival: 6:05 PM. Status: Moving',
            'direction' => 'EASTBOUND',
            'trip_number' => '4521',
            'delay_duration' => '00:10:00',
            'status' => 'Moving',
            'line_colour' => '#8B4513',
            'posted_at' => '2026-02-06 17:05:00',
        ],
    ]));

    $goCommandExit = Artisan::call('go-transit:fetch-alerts');
    $goCommandOutput = Artisan::output();

    assertEqual($goCommandExit, 0, 'go-transit:fetch-alerts exits successfully');
    assertContains($goCommandOutput, 'Done. 2 active alerts synced, 1 marked inactive.', 'go command summary output');
    assertEqual((int) GoTransitAlert::query()->where('external_id', 'notif:LW:TDELAY:stale')->value('is_active'), 0, 'go stale alert deactivated');
    assertEqual((int) GoTransitAlert::query()->where('external_id', 'notif:LW:TDELAY:keep')->value('is_active'), 1, 'go keep alert remains active');
    assertEqual((int) GoTransitAlert::query()->where('external_id', 'saag:LW:4521')->value('is_active'), 1, 'go new saag alert created active');

    $syncedGoAlert = GoTransitAlert::query()->where('external_id', 'notif:LW:TDELAY:keep')->first();
    assertTrue($syncedGoAlert !== null, 'synced go notification exists');
    assertEqual(
        $syncedGoAlert->posted_at?->toIso8601String(),
        Carbon::parse('02/06/2026 17:00:00', 'America/Toronto')->utc()->toIso8601String(),
        'go command normalizes posted_at to UTC'
    );
    assertEqual(
        $syncedGoAlert->feed_updated_at?->toIso8601String(),
        Carbon::parse('2026-02-06T17:10:00-05:00')->utc()->toIso8601String(),
        'go command stores normalized feed_updated_at'
    );

    GoTransitAlert::query()->delete();
    app()->instance(GoTransitFeedService::class, buildGoFeedStub('2026-02-06T17:10:00-05:00', [[
        'external_id' => 'notif:LW:TDELAY:job',
        'alert_type' => 'notification',
        'service_mode' => 'GO Train',
        'corridor_or_route' => 'Lakeshore West',
        'corridor_code' => 'LW',
        'sub_category' => 'TDELAY',
        'message_subject' => 'Job-triggered GO alert',
        'message_body' => null,
        'direction' => null,
        'trip_number' => null,
        'delay_duration' => null,
        'status' => 'INIT',
        'line_colour' => null,
        'posted_at' => '02/06/2026 17:00:00',
    ]]));

    (new FetchGoTransitAlertsJob)->handle();

    assertEqual((int) GoTransitAlert::query()->where('external_id', 'notif:LW:TDELAY:job')->count(), 1, 'FetchGoTransitAlertsJob dispatches go command');
    assertScheduledCommand('go-transit:fetch-alerts', '*/5 * * * *', true);

    logInfo('Phase 4: Verify provider wiring, unified query output, and home payload contract');

    FireIncident::query()->delete();
    PoliceCall::query()->delete();
    TransitAlert::query()->delete();
    GoTransitAlert::query()->delete();

    Carbon::setTestNow(Carbon::parse('2026-02-06 18:00:00'));

    $fireLatest = CarbonImmutable::parse('2026-02-06 17:54:00');
    $policeLatest = CarbonImmutable::parse('2026-02-06 17:55:00');
    $transitLatest = CarbonImmutable::parse('2026-02-06 17:56:00');
    $goLatest = CarbonImmutable::parse('2026-02-06 17:58:00');

    FireIncident::factory()->create([
        'event_num' => 'PH4-FIRE-1',
        'dispatch_time' => CarbonImmutable::parse('2026-02-06 17:30:00'),
        'feed_updated_at' => $fireLatest,
        'is_active' => true,
    ]);

    PoliceCall::factory()->create([
        'object_id' => 940001,
        'occurrence_time' => CarbonImmutable::parse('2026-02-06 17:35:00'),
        'feed_updated_at' => $policeLatest,
        'is_active' => true,
    ]);

    TransitAlert::factory()->create([
        'external_id' => 'api:phase4-transit',
        'source_feed' => 'live-api',
        'route_type' => 'Subway',
        'route' => '2',
        'title' => 'Line 2 delay',
        'description' => 'Transit delay between stations.',
        'severity' => 'Critical',
        'effect' => 'REDUCED_SERVICE',
        'direction' => 'Both Ways',
        'stop_start' => 'St George',
        'stop_end' => 'Woodbine',
        'active_period_start' => CarbonImmutable::parse('2026-02-06 17:40:00'),
        'is_active' => true,
        'feed_updated_at' => $transitLatest,
    ]);

    GoTransitAlert::factory()->create([
        'external_id' => 'notif:LW:TDELAY:phase4',
        'alert_type' => 'notification',
        'service_mode' => 'GO Train',
        'corridor_or_route' => 'Lakeshore West',
        'corridor_code' => 'LW',
        'sub_category' => 'TDELAY',
        'message_subject' => 'GO delay near Union',
        'message_body' => 'Expect 10 minute delay.',
        'direction' => 'EASTBOUND',
        'trip_number' => null,
        'delay_duration' => null,
        'posted_at' => CarbonImmutable::parse('2026-02-06 17:57:00'),
        'is_active' => true,
        'feed_updated_at' => $goLatest,
    ]);

    GoTransitAlert::factory()->inactive()->create([
        'external_id' => 'notif:LW:TDELAY:phase4-cleared',
        'alert_type' => 'notification',
        'service_mode' => 'GO Train',
        'corridor_or_route' => 'Lakeshore West',
        'corridor_code' => 'LW',
        'sub_category' => 'TDELAY',
        'message_subject' => 'Cleared GO notice',
        'posted_at' => CarbonImmutable::parse('2026-02-06 16:57:00'),
        'feed_updated_at' => CarbonImmutable::parse('2026-02-06 16:58:00'),
    ]);

    $providerClassList = collect(app()->tagged('alerts.select-providers'))
        ->map(fn (object $provider) => $provider::class)
        ->values()
        ->all();

    assertContains(implode(',', $providerClassList), GoTransitAlertSelectProvider::class, 'go provider tagged in alerts.select-providers');
    assertContains(implode(',', $providerClassList), TransitAlertSelectProvider::class, 'transit provider tagged in alerts.select-providers');

    $goProviderRow = (new GoTransitAlertSelectProvider)->select(new UnifiedAlertsCriteria(status: 'all'))
        ->where('external_id', 'notif:LW:TDELAY:phase4')
        ->first();

    assertTrue($goProviderRow !== null, 'go provider row returned');
    assertEqual($goProviderRow->id, 'go_transit:notif:LW:TDELAY:phase4', 'go provider id format');
    assertEqual($goProviderRow->source, 'go_transit', 'go provider source');
    assertEqual((string) $goProviderRow->title, 'GO delay near Union', 'go provider title');
    assertEqual((string) $goProviderRow->location_name, 'Lakeshore West', 'go provider location_name');

    $goProviderMeta = UnifiedAlertMapper::decodeMeta($goProviderRow->meta);
    foreach ([
        'alert_type',
        'service_mode',
        'sub_category',
        'corridor_code',
        'direction',
        'trip_number',
        'delay_duration',
        'line_colour',
        'message_body',
    ] as $metaKey) {
        assertArrayHasKey($metaKey, $goProviderMeta, "go provider meta has {$metaKey}");
    }

    $allResults = app(UnifiedAlertsQuery::class)->paginate(
        new UnifiedAlertsCriteria(status: 'all', perPage: 50)
    );
    $allItems = collect($allResults->items());
    $sources = $allItems->map(fn ($item) => $item->source)->unique()->values()->all();

    assertTrue(in_array('transit', $sources, true), 'unified query includes transit source');
    assertTrue(in_array('go_transit', $sources, true), 'unified query includes go_transit source');

    $goUnifiedItem = $allItems->first(fn ($item) => $item->source === 'go_transit' && $item->externalId === 'notif:LW:TDELAY:phase4');
    assertTrue($goUnifiedItem !== null, 'unified query includes expected go transit item');
    assertEqual($goUnifiedItem->title, 'GO delay near Union', 'unified go item title');
    assertArrayHasKey('service_mode', $goUnifiedItem->meta, 'unified go item has service_mode meta');

    $activeResults = app(UnifiedAlertsQuery::class)->paginate(
        new UnifiedAlertsCriteria(status: 'active', perPage: 50)
    );
    assertTrue(collect($activeResults->items())->every(fn ($item) => $item->isActive), 'status=active returns only active rows');

    $clearedResults = app(UnifiedAlertsQuery::class)->paginate(
        new UnifiedAlertsCriteria(status: 'cleared', perPage: 50)
    );
    assertTrue(collect($clearedResults->items())->isNotEmpty(), 'status=cleared returns rows');
    assertTrue(collect($clearedResults->items())->every(fn ($item) => $item->isActive === false), 'status=cleared returns only inactive rows');

    $payload = makeInertiaPayload();
    assertEqual($payload['component'] ?? null, 'gta-alerts', 'home inertia component');
    assertEqual($payload['props']['latest_feed_updated_at'] ?? null, $goLatest->toIso8601String(), 'latest_feed_updated_at prefers most recent GO feed');

    $payloadAlerts = $payload['props']['alerts']['data'] ?? null;
    assertTrue(is_array($payloadAlerts), 'home payload includes alerts.data array');
    assertTrue(
        collect($payloadAlerts)->contains(fn (array $row): bool => ($row['source'] ?? null) === 'go_transit'),
        'home payload includes go_transit source row'
    );

    $goPayloadRow = collect($payloadAlerts)->first(fn (array $row): bool => ($row['id'] ?? null) === 'go_transit:notif:LW:TDELAY:phase4');
    assertTrue(is_array($goPayloadRow), 'home payload contains expected go_transit id');
    assertArrayHasKey('meta', $goPayloadRow, 'go payload row includes meta');

    $goPayloadMeta = is_array($goPayloadRow['meta'] ?? null) ? $goPayloadRow['meta'] : [];
    foreach (['service_mode', 'sub_category', 'corridor_code', 'direction', 'message_body'] as $metaKey) {
        assertArrayHasKey($metaKey, $goPayloadMeta, "go payload meta has {$metaKey} for frontend mapping contract");
    }

    $activePayload = makeInertiaPayload(['status' => 'active']);
    $activeRows = $activePayload['props']['alerts']['data'] ?? [];
    assertTrue(is_array($activeRows), 'active payload includes alerts.data array');
    assertTrue(collect($activeRows)->every(fn (array $row): bool => ($row['is_active'] ?? null) === true), 'active payload rows are active');

    $clearedPayload = makeInertiaPayload(['status' => 'cleared']);
    $clearedRows = $clearedPayload['props']['alerts']['data'] ?? [];
    assertTrue(is_array($clearedRows), 'cleared payload includes alerts.data array');
    assertTrue(collect($clearedRows)->every(fn (array $row): bool => ($row['is_active'] ?? null) === false), 'cleared payload rows are inactive');

    if (getenv('RUN_FRONTEND_GATES') === '1') {
        runCommandGate(
            'AlertService frontend mapping/filter tests',
            'pnpm test resources/js/features/gta-alerts/services/AlertService.test.ts'
        );
    } else {
        logInfo('Skipping frontend command gate. Set RUN_FRONTEND_GATES=1 to enable.');
    }

    logInfo('=== Manual Test Completed Successfully ===');
} catch (Throwable $e) {
    $exitCode = 1;
    logError('Manual Test Failed', [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
} finally {
    try {
        Carbon::setTestNow();
    } catch (Throwable) {
    }

    try {
        Http::swap(new HttpFactory);
    } catch (Throwable) {
    }

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
