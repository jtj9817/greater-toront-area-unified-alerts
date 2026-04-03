<?php

declare(strict_types=1);

require __DIR__.'/../../vendor/autoload.php';

use App\Events\AlertCreated;
use App\Models\DrtAlert;
use App\Services\DrtServiceAlertsFeedService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

$app = require_once __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

if (app()->environment('production')) {
    fwrite(STDERR, "Error: Cannot run manual tests in production.\n");
    exit(1);
}

$testRunId = 'drt_phase3_'.now()->format('Y_m_d_His');
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
    fwrite(STDOUT, "[INFO] {$message}\n");
}

function logError(string $message, array $context = []): void
{
    Log::channel('manual_test')->error($message, $context);
    fwrite(STDOUT, "[ERROR] {$message}\n");
}

function assertTrue(bool $condition, string $message, array $context = []): void
{
    if (! $condition) {
        throw new RuntimeException('Assertion failed: '.$message.' '.json_encode($context, JSON_THROW_ON_ERROR));
    }
}

function drtPayload(string $externalId, CarbonImmutable $postedAt): array
{
    return [
        'external_id' => $externalId,
        'title' => "Service alert for {$externalId}",
        'posted_at' => $postedAt,
        'when_text' => 'Until further notice',
        'route_text' => '901, 920',
        'details_url' => "https://www.durhamregiontransit.com/en/news/{$externalId}.aspx",
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

    DrtAlert::factory()->inactive()->create([
        'external_id' => 'legacy-inactive',
        'title' => 'Legacy inactive',
        'is_active' => false,
    ]);

    $firstFeedPayload = [
        'updated_at' => CarbonImmutable::parse('2026-04-03T18:00:00Z'),
        'alerts' => [
            drtPayload('alpha', CarbonImmutable::parse('2026-04-03T17:00:00Z')),
            drtPayload('beta', CarbonImmutable::parse('2026-04-03T17:10:00Z')),
        ],
    ];

    $secondFeedPayload = [
        'updated_at' => CarbonImmutable::parse('2026-04-03T19:00:00Z'),
        'alerts' => [
            drtPayload('alpha', CarbonImmutable::parse('2026-04-03T17:00:00Z')),
            drtPayload('legacy-inactive', CarbonImmutable::parse('2026-04-03T18:20:00Z')),
            drtPayload('gamma', CarbonImmutable::parse('2026-04-03T18:40:00Z')),
        ],
    ];

    $serviceMock = Mockery::mock(DrtServiceAlertsFeedService::class);
    $serviceMock->shouldReceive('fetch')
        ->once()
        ->andReturn($firstFeedPayload);
    $serviceMock->shouldReceive('fetch')
        ->once()
        ->andReturn($secondFeedPayload);
    $serviceMock->shouldReceive('fetch')
        ->once()
        ->andThrow(new RuntimeException('Simulated DRT feed outage'));

    app()->instance(DrtServiceAlertsFeedService::class, $serviceMock);

    logInfo('Phase 2A: First fetch creates active alerts and dispatches notifications');
    Event::fake([AlertCreated::class]);

    $exitCode = Artisan::call('drt:fetch-alerts');
    $commandOutput = Artisan::output();

    assertTrue($exitCode === 0, 'first run succeeded', ['exit_code' => $exitCode]);
    assertTrue(str_contains($commandOutput, 'Done. 2 active alerts synced, 0 marked inactive.'), 'first run summary output is correct', ['output' => trim($commandOutput)]);
    assertTrue(DrtAlert::query()->where('external_id', 'alpha')->where('is_active', true)->exists(), 'alpha created active');
    assertTrue(DrtAlert::query()->where('external_id', 'beta')->where('is_active', true)->exists(), 'beta created active');
    assertTrue(DrtAlert::query()->where('external_id', 'legacy-inactive')->where('is_active', false)->exists(), 'legacy inactive remains inactive');

    $firstRunAlertIds = dispatchedAlertIds();
    assertTrue($firstRunAlertIds === ['drt:alpha', 'drt:beta'], 'first run dispatches only newly created alerts', ['alert_ids' => $firstRunAlertIds]);

    logInfo('Phase 2B: Second fetch deactivates stale alert and dispatches new/reactivated only');
    Event::fake([AlertCreated::class]);

    $exitCode = Artisan::call('drt:fetch-alerts');
    $commandOutput = Artisan::output();

    assertTrue($exitCode === 0, 'second run succeeded', ['exit_code' => $exitCode]);
    assertTrue(str_contains($commandOutput, 'Done. 3 active alerts synced, 1 marked inactive.'), 'second run summary output is correct', ['output' => trim($commandOutput)]);
    assertTrue(DrtAlert::query()->where('external_id', 'alpha')->where('is_active', true)->exists(), 'alpha remains active');
    assertTrue(DrtAlert::query()->where('external_id', 'beta')->where('is_active', false)->exists(), 'beta deactivated');
    assertTrue(DrtAlert::query()->where('external_id', 'legacy-inactive')->where('is_active', true)->exists(), 'legacy inactive reactivated');
    assertTrue(DrtAlert::query()->where('external_id', 'gamma')->where('is_active', true)->exists(), 'gamma created active');

    $alphaFeedUpdatedAt = DrtAlert::query()
        ->where('external_id', 'alpha')
        ->value('feed_updated_at');
    assertTrue((string) $alphaFeedUpdatedAt !== '', 'alpha feed_updated_at persisted');
    assertTrue(
        CarbonImmutable::parse((string) $alphaFeedUpdatedAt)->utc()->toIso8601String() === '2026-04-03T19:00:00+00:00',
        'feed_updated_at uses latest feed timestamp'
    );

    $secondRunAlertIds = dispatchedAlertIds();
    assertTrue(
        $secondRunAlertIds === ['drt:gamma', 'drt:legacy-inactive'],
        'second run dispatches only reactivated/new alerts',
        ['alert_ids' => $secondRunAlertIds]
    );

    logInfo('Phase 2C: Third fetch failure returns non-zero without mutating state');

    $activeBeforeFailure = DrtAlert::query()->where('is_active', true)->count();
    $exitCode = Artisan::call('drt:fetch-alerts');
    $commandOutput = Artisan::output();
    $activeAfterFailure = DrtAlert::query()->where('is_active', true)->count();

    assertTrue($exitCode === 1, 'failure run returns non-zero exit code', ['exit_code' => $exitCode]);
    assertTrue(str_contains($commandOutput, 'Feed fetch failed: Simulated DRT feed outage'), 'failure output contains error context', ['output' => trim($commandOutput)]);
    assertTrue($activeBeforeFailure === $activeAfterFailure, 'failure run does not mutate active alert count', [
        'before' => $activeBeforeFailure,
        'after' => $activeAfterFailure,
    ]);

    logInfo('Manual verification assertions passed');
} catch (Throwable $throwable) {
    logError('Manual test failed', [
        'message' => $throwable->getMessage(),
        'file' => $throwable->getFile(),
        'line' => $throwable->getLine(),
    ]);
    throw $throwable;
} finally {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }

    Mockery::close();

    logInfo('Phase 3: Cleanup complete via transaction rollback');
    logInfo("=== Test Run Finished: {$testRunId} ===");
    logInfo("Full log file: {$logFile}");
}
