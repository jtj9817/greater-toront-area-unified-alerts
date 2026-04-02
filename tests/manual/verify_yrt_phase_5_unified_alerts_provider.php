<?php

/**
 * Manual Test: YRT Phase 5 Unified Alerts Provider
 * Generated: 2026-04-02
 * Purpose: Verify YrtAlertSelectProvider registration, unified-contract mapping,
 * filtering behavior, and UnifiedAlertsQuery integration for source=yrt.
 */

declare(strict_types=1);

require __DIR__.'/../../vendor/autoload.php';

$app = require_once __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Enums\AlertSource;
use App\Models\YrtAlert;
use App\Services\Alerts\DTOs\UnifiedAlertsCriteria;
use App\Services\Alerts\Mappers\UnifiedAlertMapper;
use App\Services\Alerts\Providers\YrtAlertSelectProvider;
use App\Services\Alerts\UnifiedAlertsQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

if (app()->environment('production')) {
    fwrite(STDERR, "Error: Cannot run manual tests in production.\n");
    exit(1);
}

$testRunId = 'yrt_phase5_'.now()->format('Y_m_d_His');
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

try {
    DB::beginTransaction();

    logInfo("=== Starting Manual Test: {$testRunId} ===");
    logInfo('Phase 1: Setup');

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-02 12:00:00'));

    YrtAlert::query()->create([
        'external_id' => '91001',
        'title' => '91 - Aurora detour',
        'posted_at' => CarbonImmutable::now()->subMinutes(25),
        'details_url' => 'https://www.yrt.ca/en/service-updates/91001.aspx',
        'description_excerpt' => 'Route 91 detour due to construction',
        'route_text' => '91',
        'body_text' => 'Service detour via Wellington Street.',
        'list_hash' => sha1('91001-list'),
        'details_fetched_at' => CarbonImmutable::now()->subMinutes(10),
        'is_active' => true,
        'feed_updated_at' => CarbonImmutable::now()->subMinutes(5),
    ]);

    YrtAlert::query()->create([
        'external_id' => '92002',
        'title' => '92 - Newmarket stop closure',
        'posted_at' => CarbonImmutable::now()->subHours(2),
        'details_url' => 'https://www.yrt.ca/en/service-updates/92002.aspx',
        'description_excerpt' => null,
        'route_text' => null,
        'body_text' => null,
        'list_hash' => sha1('92002-list'),
        'details_fetched_at' => null,
        'is_active' => false,
        'feed_updated_at' => null,
    ]);

    logInfo('Phase 2A: Verify provider registration and source identity');

    $providerClasses = collect(app()->tagged('alerts.select-providers'))
        ->map(fn (object $provider): string => $provider::class)
        ->all();

    assertTrue(
        in_array(YrtAlertSelectProvider::class, $providerClasses, true),
        'YrtAlertSelectProvider is tagged in alerts.select-providers',
        ['providers' => $providerClasses]
    );

    $provider = app(YrtAlertSelectProvider::class);
    assertTrue(
        $provider->source() === AlertSource::Yrt->value,
        'provider source returns yrt enum value',
        ['source' => $provider->source()]
    );

    logInfo('Phase 2B: Verify provider unified-row contract and metadata');

    $row = $provider
        ->select(new UnifiedAlertsCriteria(status: 'all', source: 'yrt'))
        ->where('external_id', '91001')
        ->first();

    assertTrue($row !== null, 'provider returns a row for active YRT alert');
    assertTrue($row->id === 'yrt:91001', 'provider canonical id is source-prefixed', ['id' => $row->id]);
    assertTrue($row->source === 'yrt', 'provider row source is yrt', ['source' => $row->source]);
    assertTrue($row->external_id === '91001', 'provider external_id remains unprefixed', ['external_id' => $row->external_id]);
    assertTrue($row->location_name === '91', 'provider maps route_text to location_name', ['location_name' => $row->location_name]);
    assertTrue($row->lat === null && $row->lng === null, 'provider keeps YRT coordinates null');

    $meta = UnifiedAlertMapper::decodeMeta($row->meta);
    assertTrue(array_key_exists('details_url', $meta), 'meta contains details_url key');
    assertTrue(array_key_exists('description_excerpt', $meta), 'meta contains description_excerpt key');
    assertTrue(array_key_exists('body_text', $meta), 'meta contains body_text key');
    assertTrue(array_key_exists('posted_at', $meta), 'meta contains posted_at key');
    assertTrue(array_key_exists('feed_updated_at', $meta), 'meta contains feed_updated_at key');
    assertTrue($meta['details_url'] === 'https://www.yrt.ca/en/service-updates/91001.aspx', 'meta details_url value matches');

    $nullMetaRow = $provider
        ->select(new UnifiedAlertsCriteria(status: 'all', source: 'yrt'))
        ->where('external_id', '92002')
        ->first();

    $nullMeta = UnifiedAlertMapper::decodeMeta($nullMetaRow?->meta);
    assertTrue($nullMeta['description_excerpt'] === null, 'meta is null-safe for description_excerpt');
    assertTrue($nullMeta['body_text'] === null, 'meta is null-safe for body_text');
    assertTrue(array_key_exists('feed_updated_at', $nullMeta), 'meta includes feed_updated_at key even when nullable');
    assertTrue($nullMeta['feed_updated_at'] === null, 'meta is null-safe for feed_updated_at');

    logInfo('Phase 2C: Verify provider filtering behavior');

    $activeCount = $provider->select(new UnifiedAlertsCriteria(status: 'active', source: 'yrt'))->count();
    $clearedCount = $provider->select(new UnifiedAlertsCriteria(status: 'cleared', source: 'yrt'))->count();
    $sinceCount = $provider->select(new UnifiedAlertsCriteria(status: 'all', source: 'yrt', since: '1h'))->count();
    $mismatchSourceCount = $provider->select(new UnifiedAlertsCriteria(status: 'all', source: 'fire'))->count();

    assertTrue($activeCount === 1, 'active filter returns only active yrt alert', ['count' => $activeCount]);
    assertTrue($clearedCount === 1, 'cleared filter returns only inactive yrt alert', ['count' => $clearedCount]);
    assertTrue($sinceCount === 1, 'since filter keeps only recent yrt alerts', ['count' => $sinceCount]);
    assertTrue($mismatchSourceCount === 0, 'non-yrt source filter returns no rows for yrt provider');

    logInfo('Phase 2D: Verify UnifiedAlertsQuery integration for source=yrt');

    $query = app(UnifiedAlertsQuery::class);
    $results = $query->paginate(new UnifiedAlertsCriteria(status: 'all', source: 'yrt', perPage: 50));
    $items = $results->items();

    assertTrue(count($items) === 2, 'unified query returns both yrt rows when source=yrt', ['count' => count($items)]);
    assertTrue($items[0]->source === 'yrt', 'first unified result has yrt source', ['source' => $items[0]->source]);
    assertTrue(str_starts_with($items[0]->id, 'yrt:'), 'first unified result id is yrt-prefixed', ['id' => $items[0]->id]);

    $driver = DB::getDriverName();
    if ($driver !== 'mysql' && $driver !== 'mariadb') {
        $searchResults = $query->paginate(new UnifiedAlertsCriteria(
            status: 'all',
            source: 'yrt',
            query: 'aurora',
            perPage: 50,
        ));

        assertTrue($searchResults->total() === 1, 'query filtering finds expected yrt alert by title');
        assertTrue($searchResults->items()[0]->externalId === '91001', 'search returns expected yrt alert');
    } else {
        logInfo('Skipping query-term assertion on MySQL path (FULLTEXT index not part of YRT phase 5 scope).', [
            'driver' => $driver,
        ]);
    }

    logInfo('Manual verification assertions passed');
    logInfo('Phase 3: Cleanup');

    CarbonImmutable::setTestNow();
    DB::rollBack();

    logInfo('Database transaction rolled back');
    logInfo("=== Manual Test Completed: {$testRunId} ===");

    echo "\n✓ Manual verification passed. Log: {$logFile}\n";
    exit(0);
} catch (Throwable $e) {
    CarbonImmutable::setTestNow();

    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }

    logError('Manual verification failed', [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);

    echo "\n✗ Manual verification failed. Log: {$logFile}\n";
    fwrite(STDERR, $e->getMessage()."\n");
    exit(1);
}
