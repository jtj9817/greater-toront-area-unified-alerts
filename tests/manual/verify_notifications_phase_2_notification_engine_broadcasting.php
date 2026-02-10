<?php

/**
 * Manual Test: Notifications - Phase 2 Notification Engine & Broadcasting
 * Generated: 2026-02-10
 * Purpose: Verify Phase 2 deliverables for notification engine behavior:
 * - Broadcasting/realtime configuration and scheduler wiring
 * - AlertNotificationSent event channel + payload contract
 * - Matcher behavior and AlertCreated lifecycle dispatch to queue
 * - Delivery job broadcast + idempotency behavior
 * - Daily digest aggregation window and duplicate prevention
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

use App\Events\AlertCreated;
use App\Events\AlertNotificationSent;
use App\Jobs\DeliverAlertNotificationJob;
use App\Jobs\GenerateDailyDigestJob;
use App\Models\NotificationLog;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Services\Notifications\NotificationAlert;
use App\Services\Notifications\NotificationMatcher;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

$testRunId = 'notifications_phase_2_notification_engine_broadcasting_'.Carbon::now()->format('Y_m_d_His');
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

function assertContainsText(string $needle, string $haystack, string $label): void
{
    assertTrue(str_contains($haystack, $needle), $label, ['needle' => $needle]);
}

/**
 * @param  array<int, int|string>  $actual
 * @param  array<int, int|string>  $expected
 */
function assertSameValues(array $actual, array $expected, string $label): void
{
    $normalize = static function (array $values): array {
        $normalized = array_map(static fn (int|string $value): string => (string) $value, $values);
        sort($normalized);

        return array_values($normalized);
    };

    assertEqual($normalize($actual), $normalize($expected), $label);
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

    if (! Schema::hasTable('notification_preferences') || ! Schema::hasTable('notification_logs')) {
        logInfo('notification tables missing; running migrations for testing database');
        Artisan::call('migrate', ['--force' => true]);
        logInfo('Migration output', ['output' => trim(Artisan::output())]);
    }

    DB::beginTransaction();
    $txStarted = true;

    logInfo('=== Starting Manual Test: Notifications Phase 2 Notification Engine & Broadcasting ===');

    logInfo('Phase 1: Real-time infrastructure configuration verification');

    $envExamplePath = base_path('.env.example');
    assertTrue(file_exists($envExamplePath), '.env.example exists');
    $envExampleContents = file_get_contents($envExamplePath);
    assertTrue(is_string($envExampleContents), '.env.example is readable');
    assertContainsText('BROADCAST_CONNECTION=log', $envExampleContents, '.env.example defines BROADCAST_CONNECTION');
    assertContainsText('REVERB_APP_KEY=', $envExampleContents, '.env.example contains REVERB_APP_KEY');
    assertContainsText('PUSHER_APP_KEY=', $envExampleContents, '.env.example contains PUSHER_APP_KEY');
    assertContainsText('# Set BROADCAST_CONNECTION to "reverb" or "pusher" to enable real-time delivery.', $envExampleContents, '.env.example documents real-time broadcaster setup');

    assertEqual(config('broadcasting.connections.reverb.driver'), 'reverb', 'broadcasting config has reverb driver');
    assertEqual(config('broadcasting.connections.pusher.driver'), 'pusher', 'broadcasting config has pusher driver');
    assertTrue(in_array(config('broadcasting.default'), ['reverb', 'pusher', 'log', 'null'], true), 'broadcasting.default is a supported configured driver', [
        'default' => config('broadcasting.default'),
    ]);

    $channelsPath = base_path('routes/channels.php');
    assertTrue(file_exists($channelsPath), 'routes/channels.php exists');
    $channelsContents = file_get_contents($channelsPath);
    assertTrue(is_string($channelsContents), 'routes/channels.php is readable');
    assertContainsText('users.{userId}.notifications', $channelsContents, 'private user notifications channel is registered');

    $schedule = app(Schedule::class);
    $digestScheduleEvent = null;
    foreach ($schedule->events() as $scheduledEvent) {
        if ($scheduledEvent instanceof CallbackEvent && ($scheduledEvent->description ?? null) === GenerateDailyDigestJob::class) {
            $digestScheduleEvent = $scheduledEvent;
            break;
        }
    }

    assertTrue($digestScheduleEvent instanceof CallbackEvent, 'GenerateDailyDigestJob is registered in scheduler');
    if ($digestScheduleEvent instanceof CallbackEvent) {
        assertEqual($digestScheduleEvent->expression, '10 0 * * *', 'GenerateDailyDigestJob runs daily at 00:10');
        assertTrue($digestScheduleEvent->withoutOverlapping === true, 'GenerateDailyDigestJob schedule uses withoutOverlapping');
    }

    logInfo('Phase 2: AlertNotificationSent event contract verification');

    assertTrue(in_array(ShouldBroadcastNow::class, class_implements(AlertNotificationSent::class), true), 'AlertNotificationSent implements ShouldBroadcastNow');

    $alertNotificationEvent = new AlertNotificationSent(
        userId: 42,
        alertId: 'police:123',
        source: 'police',
        severity: 'major',
        summary: 'Assault in progress',
        sentAt: '2026-02-10T16:30:00+00:00',
    );

    assertEqual($alertNotificationEvent->broadcastAs(), 'alert.notification.sent', 'broadcast event alias');

    $broadcastChannels = $alertNotificationEvent->broadcastOn();
    assertEqual(count($broadcastChannels), 1, 'broadcast event has exactly one channel');
    assertTrue($broadcastChannels[0] instanceof PrivateChannel, 'broadcast channel is private');
    assertEqual($broadcastChannels[0]->name, 'private-users.42.notifications', 'broadcast channel name targets expected user');

    assertEqual($alertNotificationEvent->broadcastWith(), [
        'alert_id' => 'police:123',
        'source' => 'police',
        'severity' => 'major',
        'summary' => 'Assault in progress',
        'sent_at' => '2026-02-10T16:30:00+00:00',
    ], 'broadcast payload includes expected toast/inbox fields');

    logInfo('Phase 3: Matcher behavior and AlertCreated lifecycle dispatch verification');

    $listeners = app('events')->getListeners(AlertCreated::class);
    assertTrue(count($listeners) > 0, 'AlertCreated event has at least one registered listener');

    $matchingPreference = NotificationPreference::factory()->create([
        'alert_type' => 'emergency',
        'severity_threshold' => 'major',
        'geofences' => [
            ['name' => 'Downtown', 'lat' => 43.7000, 'lng' => -79.4000, 'radius_km' => 3],
        ],
        'subscribed_routes' => [],
        'push_enabled' => true,
    ]);

    NotificationPreference::factory()->create([
        'alert_type' => 'emergency',
        'severity_threshold' => 'major',
        'geofences' => [
            ['name' => 'Downtown', 'lat' => 43.7000, 'lng' => -79.4000, 'radius_km' => 3],
        ],
        'subscribed_routes' => [],
        'push_enabled' => false,
    ]);

    NotificationPreference::factory()->create([
        'alert_type' => 'transit',
        'severity_threshold' => 'minor',
        'geofences' => [],
        'subscribed_routes' => [],
        'push_enabled' => true,
    ]);

    NotificationPreference::factory()->create([
        'alert_type' => 'emergency',
        'severity_threshold' => 'critical',
        'geofences' => [],
        'subscribed_routes' => [],
        'push_enabled' => true,
    ]);

    NotificationPreference::factory()->create([
        'alert_type' => 'emergency',
        'severity_threshold' => 'major',
        'geofences' => [
            ['name' => 'Far Away', 'lat' => 44.1000, 'lng' => -79.1000, 'radius_km' => 1],
        ],
        'subscribed_routes' => [],
        'push_enabled' => true,
    ]);

    $emergencyAlert = new NotificationAlert(
        alertId: 'police:manual-phase2-001',
        source: 'police',
        severity: 'major',
        summary: 'Police response in progress',
        occurredAt: CarbonImmutable::parse('2026-02-10T16:00:00Z'),
        lat: 43.7010,
        lng: -79.4010,
    );

    $matcher = app(NotificationMatcher::class);
    $matchedEmergencyUserIds = $matcher
        ->matchingPreferences($emergencyAlert)
        ->pluck('user_id')
        ->map(static fn (int|string $userId): int => (int) $userId)
        ->values()
        ->all();

    assertSameValues($matchedEmergencyUserIds, [$matchingPreference->user_id], 'matcher returns only push-enabled, severity/geofence-matching emergency user');

    Queue::fake();
    event(new AlertCreated($emergencyAlert));

    Queue::assertPushed(DeliverAlertNotificationJob::class, 1);
    Queue::assertPushed(DeliverAlertNotificationJob::class, function (DeliverAlertNotificationJob $job) use ($matchingPreference): bool {
        return $job->userId === $matchingPreference->user_id
            && $job->payload['alert_id'] === 'police:manual-phase2-001'
            && $job->payload['severity'] === 'major'
            && $job->payload['source'] === 'police';
    });
    logInfo('Assertion passed: AlertCreated listener queued only matching emergency user');

    // Reset preferences to isolate transit route assertions from emergency scenario setup.
    NotificationPreference::query()->delete();

    $routeMatchingPreference = NotificationPreference::factory()->create([
        'alert_type' => 'transit',
        'severity_threshold' => 'minor',
        'geofences' => [],
        'subscribed_routes' => ['501', 'GO-LW'],
        'push_enabled' => true,
    ]);

    NotificationPreference::factory()->create([
        'alert_type' => 'transit',
        'severity_threshold' => 'minor',
        'geofences' => [],
        'subscribed_routes' => ['504'],
        'push_enabled' => true,
    ]);

    $transitAlert = new NotificationAlert(
        alertId: 'transit:manual-phase2-501',
        source: 'transit',
        severity: 'minor',
        summary: 'Route 501 service adjustment',
        occurredAt: CarbonImmutable::parse('2026-02-10T17:00:00Z'),
        routes: ['501'],
    );

    $matchedTransitUserIds = $matcher
        ->matchingPreferences($transitAlert)
        ->pluck('user_id')
        ->map(static fn (int|string $userId): int => (int) $userId)
        ->values()
        ->all();

    assertSameValues($matchedTransitUserIds, [$routeMatchingPreference->user_id], 'matcher returns only matching subscribed route user for transit alert');

    Queue::fake();
    event(new AlertCreated($transitAlert));

    Queue::assertPushed(DeliverAlertNotificationJob::class, 1);
    Queue::assertPushed(DeliverAlertNotificationJob::class, function (DeliverAlertNotificationJob $job) use ($routeMatchingPreference): bool {
        return $job->userId === $routeMatchingPreference->user_id
            && $job->payload['alert_id'] === 'transit:manual-phase2-501';
    });
    logInfo('Assertion passed: AlertCreated listener queued subscribed transit route user');

    $noRouteTransitAlert = new NotificationAlert(
        alertId: 'transit:manual-phase2-no-route',
        source: 'transit',
        severity: 'minor',
        summary: 'Route details unavailable',
        occurredAt: CarbonImmutable::parse('2026-02-10T17:30:00Z'),
        routes: [],
    );
    $matchedNoRouteTransitUserIds = $matcher
        ->matchingPreferences($noRouteTransitAlert)
        ->pluck('user_id')
        ->map(static fn (int|string $userId): int => (int) $userId)
        ->values()
        ->all();
    assertSameValues($matchedNoRouteTransitUserIds, [], 'transit subscriptions do not match when alert has no routes');

    logInfo('Phase 4: DeliverAlertNotificationJob broadcast + idempotency verification');

    $deliveryUser = User::factory()->create();
    NotificationPreference::factory()->create([
        'user_id' => $deliveryUser->id,
        'push_enabled' => true,
        'alert_type' => 'all',
        'severity_threshold' => 'all',
        'geofences' => [],
        'subscribed_routes' => [],
    ]);

    NotificationLog::factory()->create([
        'user_id' => $deliveryUser->id,
        'alert_id' => 'police:manual-existing-sent',
        'delivery_method' => 'in_app',
        'status' => 'sent',
        'sent_at' => CarbonImmutable::parse('2026-02-10T10:00:00Z'),
    ]);

    Event::fake([AlertNotificationSent::class]);
    $retryDeliveryJob = new DeliverAlertNotificationJob(
        userId: $deliveryUser->id,
        payload: [
            'alert_id' => 'police:manual-existing-sent',
            'source' => 'police',
            'severity' => 'major',
            'summary' => 'Police response in progress',
            'occurred_at' => '2026-02-10T09:58:00+00:00',
            'routes' => [],
            'metadata' => [],
        ],
    );
    $retryDeliveryJob->handle();

    Event::assertDispatched(AlertNotificationSent::class, function (AlertNotificationSent $event) use ($deliveryUser): bool {
        return $event->userId === $deliveryUser->id
            && $event->alertId === 'police:manual-existing-sent'
            && $event->source === 'police'
            && $event->severity === 'major';
    });

    $existingSentLog = NotificationLog::query()
        ->where('user_id', $deliveryUser->id)
        ->where('alert_id', 'police:manual-existing-sent')
        ->where('delivery_method', 'in_app')
        ->firstOrFail();
    assertEqual($existingSentLog->status, 'delivered', 'existing sent notification transitions to delivered after broadcast retry');

    Event::fake([AlertNotificationSent::class]);
    $newDeliveryJob = new DeliverAlertNotificationJob(
        userId: $deliveryUser->id,
        payload: [
            'alert_id' => 'police:manual-new-001',
            'source' => 'police',
            'severity' => 'major',
            'summary' => 'New police alert',
            'occurred_at' => '2026-02-10T11:30:00+00:00',
            'routes' => [],
            'metadata' => ['division' => 'D42'],
        ],
    );
    $newDeliveryJob->handle();

    Event::assertDispatched(AlertNotificationSent::class, function (AlertNotificationSent $event) use ($deliveryUser): bool {
        return $event->userId === $deliveryUser->id
            && $event->alertId === 'police:manual-new-001';
    });

    $newLog = NotificationLog::query()
        ->where('user_id', $deliveryUser->id)
        ->where('alert_id', 'police:manual-new-001')
        ->where('delivery_method', 'in_app')
        ->firstOrFail();

    assertEqual($newLog->status, 'delivered', 'new delivery creates notification log and marks delivered');
    assertTrue(is_array($newLog->metadata), 'new delivery metadata is stored as array');
    assertEqual($newLog->metadata['source'] ?? null, 'police', 'new delivery metadata stores source');
    assertEqual($newLog->metadata['severity'] ?? null, 'major', 'new delivery metadata stores severity');
    assertEqual($newLog->metadata['summary'] ?? null, 'New police alert', 'new delivery metadata stores summary');

    NotificationLog::factory()->create([
        'user_id' => $deliveryUser->id,
        'alert_id' => 'police:manual-already-delivered',
        'delivery_method' => 'in_app',
        'status' => 'delivered',
        'sent_at' => CarbonImmutable::parse('2026-02-10T12:00:00Z'),
    ]);

    Event::fake([AlertNotificationSent::class]);
    $alreadyDeliveredJob = new DeliverAlertNotificationJob(
        userId: $deliveryUser->id,
        payload: [
            'alert_id' => 'police:manual-already-delivered',
            'source' => 'police',
            'severity' => 'major',
            'summary' => 'Already delivered alert',
            'occurred_at' => '2026-02-10T11:58:00+00:00',
            'routes' => [],
            'metadata' => [],
        ],
    );
    $alreadyDeliveredJob->handle();
    Event::assertNotDispatched(AlertNotificationSent::class);
    logInfo('Assertion passed: delivery job does not rebroadcast already delivered logs');

    $pushDisabledUser = User::factory()->create();
    NotificationPreference::factory()->create([
        'user_id' => $pushDisabledUser->id,
        'push_enabled' => false,
        'alert_type' => 'all',
        'severity_threshold' => 'all',
        'geofences' => [],
        'subscribed_routes' => [],
    ]);

    Event::fake([AlertNotificationSent::class]);
    $pushDisabledJob = new DeliverAlertNotificationJob(
        userId: $pushDisabledUser->id,
        payload: [
            'alert_id' => 'police:manual-push-disabled',
            'source' => 'police',
            'severity' => 'major',
            'summary' => 'Push disabled test',
            'occurred_at' => '2026-02-10T12:30:00+00:00',
            'routes' => [],
            'metadata' => [],
        ],
    );
    $pushDisabledJob->handle();
    Event::assertNotDispatched(AlertNotificationSent::class);
    assertEqual(
        NotificationLog::query()
            ->where('user_id', $pushDisabledUser->id)
            ->where('alert_id', 'police:manual-push-disabled')
            ->count(),
        0,
        'delivery job respects push opt-out and creates no log'
    );

    logInfo('Phase 5: Daily digest aggregation and duplicate prevention verification');

    Carbon::setTestNow(CarbonImmutable::parse('2026-02-11T08:00:00Z'));

    $digestPreference = NotificationPreference::factory()->create([
        'digest_mode' => true,
        'push_enabled' => true,
        'geofences' => [],
        'subscribed_routes' => [],
    ]);

    $nonDigestPreference = NotificationPreference::factory()->create([
        'digest_mode' => false,
        'push_enabled' => true,
        'geofences' => [],
        'subscribed_routes' => [],
    ]);

    $digestNoNotificationsPreference = NotificationPreference::factory()->create([
        'digest_mode' => true,
        'push_enabled' => true,
        'geofences' => [],
        'subscribed_routes' => [],
    ]);

    NotificationLog::factory()->create([
        'user_id' => $digestPreference->user_id,
        'alert_id' => 'police:prev-day-midday',
        'delivery_method' => 'in_app',
        'status' => 'sent',
        'sent_at' => CarbonImmutable::parse('2026-02-10T12:00:00Z'),
    ]);
    NotificationLog::factory()->create([
        'user_id' => $digestPreference->user_id,
        'alert_id' => 'police:window-start',
        'delivery_method' => 'in_app',
        'status' => 'sent',
        'sent_at' => CarbonImmutable::parse('2026-02-10T00:00:00Z'),
    ]);
    NotificationLog::factory()->create([
        'user_id' => $digestPreference->user_id,
        'alert_id' => 'police:window-end-excluded',
        'delivery_method' => 'in_app',
        'status' => 'sent',
        'sent_at' => CarbonImmutable::parse('2026-02-11T00:00:00Z'),
    ]);
    NotificationLog::factory()->create([
        'user_id' => $nonDigestPreference->user_id,
        'alert_id' => 'police:non-digest-user',
        'delivery_method' => 'in_app',
        'status' => 'sent',
        'sent_at' => CarbonImmutable::parse('2026-02-10T12:00:00Z'),
    ]);

    app(GenerateDailyDigestJob::class)->handle();
    app(GenerateDailyDigestJob::class)->handle();

    $digestLogs = NotificationLog::query()
        ->where('user_id', $digestPreference->user_id)
        ->where('delivery_method', 'in_app_digest')
        ->where('alert_id', 'digest:2026-02-10')
        ->get();

    assertEqual($digestLogs->count(), 1, 'digest log is created exactly once for digest-enabled user');
    $digestLog = $digestLogs->firstOrFail();
    assertTrue(is_array($digestLog->metadata), 'digest metadata stored as array');
    assertEqual($digestLog->metadata['type'] ?? null, 'daily_digest', 'digest metadata type');
    assertEqual($digestLog->metadata['digest_date'] ?? null, '2026-02-10', 'digest metadata digest_date');
    assertEqual($digestLog->metadata['window_start'] ?? null, '2026-02-10T00:00:00+00:00', 'digest metadata window_start');
    assertEqual($digestLog->metadata['window_end'] ?? null, '2026-02-11T00:00:00+00:00', 'digest metadata window_end');
    assertEqual($digestLog->metadata['total_notifications'] ?? null, 2, 'digest counts only notifications in prior-day window');

    assertEqual(
        NotificationLog::query()
            ->where('user_id', $nonDigestPreference->user_id)
            ->where('delivery_method', 'in_app_digest')
            ->count(),
        0,
        'non-digest users do not receive digest entries'
    );

    assertEqual(
        NotificationLog::query()
            ->where('user_id', $digestNoNotificationsPreference->user_id)
            ->where('delivery_method', 'in_app_digest')
            ->count(),
        0,
        'digest-enabled users with zero prior-day notifications do not receive empty digest entries'
    );

    Carbon::setTestNow();

    logInfo('=== Manual Test Completed Successfully ===');
} catch (Throwable $e) {
    $exitCode = 1;
    logError('Manual Test Failed', [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
} finally {
    if (Carbon::hasTestNow()) {
        Carbon::setTestNow();
    }

    if ($txStarted && DB::transactionLevel() > 0) {
        DB::rollBack();
        logInfo('Rolled back transaction; no persistent data changes were kept.');
    }

    logInfo('Manual test log file', ['path' => $logFileRelative]);
}

exit($exitCode);
