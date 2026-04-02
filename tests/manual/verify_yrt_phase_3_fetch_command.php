<?php

/**
 * Manual Test: YRT Phase 3 Fetch Command (Sync + Notifications)
 * Generated: 2026-04-01
 * Purpose: Verify yrt:fetch-alerts sync lifecycle, deactivation behavior,
 * notification dispatch rules, and graceful failure handling.
 */

declare(strict_types=1);

require __DIR__.'/../../vendor/autoload.php';

$app = require_once __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Events\AlertCreated;
use App\Models\YrtAlert;
use App\Services\YrtServiceAdvisoriesFeedService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

if (app()->environment('production')) {
    fwrite(STDERR, "Error: Cannot run manual tests in production.\n");
    exit(1);
}

$testRunId = 'yrt_phase3_'.now()->format('Y_m_d_His');
$logFile = storage_path("logs/manual_tests/{$testRunId}.log");

if (! is_dir(dirname($logFile))) {
    mkdir(dirname($logFile), 0755, true);
}

config(['logging.channels.manual_test' => [
    'driver' => 'single',
    'path' => $logFile,
    'level' => 'debug',
]]);

function logInfo(string $message, array $context = []): void
{
    Log::channel('manual_test')->info($message, $context);
    echo "[INFO] {$message}\n";
}

function logError(string $message, array $context = []): void
{
    Log::channel('manual_test')->error($message, $context);
    echo "[ERROR] {$message}\n";
}

function assertTrue(bool $condition, string $message, array $context = []): void
{
    if (! $condition) {
        throw new RuntimeException('Assertion failed: '.$message.' '.json_encode($context, JSON_THROW_ON_ERROR));
    }
}

function payload(string $externalId, CarbonImmutable $postedAt): array
{
    return [
        'external_id' => $externalId,
        'title' => "Service advisory for {$externalId}",
        'posted_at' => $postedAt,
        'details_url' => "https://www.yrt.ca/en/service-updates/{$externalId}.aspx",
        'description_excerpt' => "Description for {$externalId}",
        'route_text' => '99 - Yonge',
        'body_text' => "Body for {$externalId}",
        'list_hash' => sha1("{$externalId}-hash"),
        'details_fetched_at' => $postedAt->addMinutes(3),
        'is_active' => true,
    ];
}

function dispatchedAlertIds(): array
{
    $records = Event::dispatched(AlertCreated::class);
    $alertIds = [];

    foreach ($records as $record) {
        $event = $record['event'] ?? $record[0] ?? null;
        if ($event instanceof AlertCreated) {
            $alertIds[] = $event->alert->alertId;
        }
    }

    sort($alertIds);

    return $alertIds;
}

try {
    DB::beginTransaction();

    logInfo("=== Starting Manual Test: {$testRunId} ===");
    logInfo('Phase 1: Setup');

    YrtAlert::factory()->inactive()->create([
        'external_id' => 'gamma',
        'title' => 'Existing inactive alert',
        'is_active' => false,
    ]);

    $firstFeedPayload = [
        'updated_at' => CarbonImmutable::parse('2026-04-01T15:00:00Z'),
        'alerts' => [
            payload('alpha', CarbonImmutable::parse('2026-04-01T14:00:00Z')),
            payload('beta', CarbonImmutable::parse('2026-04-01T14:10:00Z')),
        ],
    ];

    $secondFeedPayload = [
        'updated_at' => CarbonImmutable::parse('2026-04-01T16:00:00Z'),
        'alerts' => [
            payload('alpha', CarbonImmutable::parse('2026-04-01T14:00:00Z')),
            payload('gamma', CarbonImmutable::parse('2026-04-01T15:20:00Z')),
            payload('delta', CarbonImmutable::parse('2026-04-01T15:40:00Z')),
        ],
    ];

    $serviceMock = Mockery::mock(YrtServiceAdvisoriesFeedService::class);
    $serviceMock->shouldReceive('fetch')
        ->once()
        ->andReturn($firstFeedPayload);
    $serviceMock->shouldReceive('fetch')
        ->once()
        ->andReturn($secondFeedPayload);
    $serviceMock->shouldReceive('fetch')
        ->once()
        ->andThrow(new RuntimeException('Simulated feed outage'));

    app()->instance(YrtServiceAdvisoriesFeedService::class, $serviceMock);

    logInfo('Phase 2A: First fetch creates active alerts and dispatches notifications');
    Event::fake([AlertCreated::class]);

    $exitCode = Artisan::call('yrt:fetch-alerts');
    $commandOutput = Artisan::output();

    assertTrue($exitCode === 0, 'first run succeeded', ['exit_code' => $exitCode]);
    assertTrue(str_contains($commandOutput, 'Done. 2 active alerts synced, 0 marked inactive.'), 'first run summary output is correct', ['output' => trim($commandOutput)]);
    assertTrue(YrtAlert::query()->where('external_id', 'alpha')->where('is_active', true)->exists(), 'alpha created active');
    assertTrue(YrtAlert::query()->where('external_id', 'beta')->where('is_active', true)->exists(), 'beta created active');
    assertTrue(YrtAlert::query()->where('external_id', 'gamma')->where('is_active', false)->exists(), 'gamma remains inactive');

    $firstRunAlertIds = dispatchedAlertIds();
    assertTrue($firstRunAlertIds === ['yrt:alpha', 'yrt:beta'], 'first run dispatches only newly created alerts', ['alert_ids' => $firstRunAlertIds]);

    logInfo('Phase 2B: Second fetch deactivates stale alert and dispatches only new/reactivated');
    Event::fake([AlertCreated::class]);

    $exitCode = Artisan::call('yrt:fetch-alerts');
    $commandOutput = Artisan::output();

    assertTrue($exitCode === 0, 'second run succeeded', ['exit_code' => $exitCode]);
    assertTrue(str_contains($commandOutput, 'Done. 3 active alerts synced, 1 marked inactive.'), 'second run summary output is correct', ['output' => trim($commandOutput)]);
    assertTrue(YrtAlert::query()->where('external_id', 'alpha')->where('is_active', true)->exists(), 'alpha remains active');
    assertTrue(YrtAlert::query()->where('external_id', 'beta')->where('is_active', false)->exists(), 'beta deactivated');
    assertTrue(YrtAlert::query()->where('external_id', 'gamma')->where('is_active', true)->exists(), 'gamma reactivated');
    assertTrue(YrtAlert::query()->where('external_id', 'delta')->where('is_active', true)->exists(), 'delta created active');

    $alphaFeedUpdatedAt = YrtAlert::query()
        ->where('external_id', 'alpha')
        ->value('feed_updated_at');
    assertTrue((string) $alphaFeedUpdatedAt !== '', 'alpha feed_updated_at persisted');
    assertTrue(
        CarbonImmutable::parse((string) $alphaFeedUpdatedAt)->utc()->toIso8601String() === '2026-04-01T16:00:00+00:00',
        'feed_updated_at uses latest feed timestamp'
    );

    $secondRunAlertIds = dispatchedAlertIds();
    assertTrue(
        $secondRunAlertIds === ['yrt:delta', 'yrt:gamma'],
        'second run dispatches only reactivated/new alerts',
        ['alert_ids' => $secondRunAlertIds]
    );

    logInfo('Phase 2C: Third fetch failure returns non-zero without mutating sync state');

    $activeBeforeFailure = YrtAlert::query()->where('is_active', true)->count();
    $exitCode = Artisan::call('yrt:fetch-alerts');
    $commandOutput = Artisan::output();
    $activeAfterFailure = YrtAlert::query()->where('is_active', true)->count();

    assertTrue($exitCode === 1, 'failure run returns non-zero exit code', ['exit_code' => $exitCode]);
    assertTrue(str_contains($commandOutput, 'Feed fetch failed: Simulated feed outage'), 'failure output contains error context', ['output' => trim($commandOutput)]);
    assertTrue($activeBeforeFailure === $activeAfterFailure, 'failure run does not mutate active alert count', [
        'before' => $activeBeforeFailure,
        'after' => $activeAfterFailure,
    ]);

    logInfo('Manual verification assertions passed');
    logInfo('Phase 3: Cleanup');

    DB::rollBack();
    Mockery::close();

    logInfo('Database transaction rolled back');
    logInfo("=== Manual Test Completed: {$testRunId} ===");

    echo "\n✓ Manual verification passed. Log: {$logFile}\n";
    exit(0);
} catch (Throwable $e) {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }

    Mockery::close();

    logError('Manual verification failed', [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);

    echo "\n✗ Manual verification failed. Log: {$logFile}\n";
    fwrite(STDERR, $e->getMessage()."\n");
    exit(1);
}
