<?php

/**
 * Manual Test: DRT Phase 5 Unified Alerts Provider
 * Generated: 2026-04-03
 * Purpose: Verify DrtAlertSelectProvider registration, unified select columns,
 * meta JSON shape, criteria filters, and UnifiedAlertsQuery integration.
 */

declare(strict_types=1);

require __DIR__.'/../../vendor/autoload.php';

$app = require_once __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Models\DrtAlert;
use App\Services\Alerts\DTOs\UnifiedAlertsCriteria;
use App\Services\Alerts\Mappers\UnifiedAlertMapper;
use App\Services\Alerts\Providers\DrtAlertSelectProvider;
use App\Services\Alerts\UnifiedAlertsQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

if (app()->environment('production')) {
    fwrite(STDERR, "Error: Cannot run manual tests in production.\n");
    exit(1);
}

$testRunId = 'drt_phase5_'.now()->format('Y_m_d_His');
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

    // ── Phase 1: Data Setup ──────────────────────────────────────────
    logInfo('Phase 1: Setup - creating DRT test records');

    $now = CarbonImmutable::now();
    $twoHoursAgo = $now->subHours(2);

    $activeAlert = DrtAlert::factory()->create([
        'external_id' => 'conlin-grandview-detour',
        'title' => 'Detour on Conlin Road at Grandview',
        'posted_at' => $now,
        'when_text' => 'Effective April 3, 2026 until further notice',
        'route_text' => '900 and 920',
        'details_url' => 'https://www.durhamregiontransit.com/en/news/conlin-grandview-detour.aspx',
        'body_text' => 'Buses will not serve stops on Conlin Road between Grandview Drive and Harmony Road due to construction. Please use stops on Grandview Avenue instead.',
        'is_active' => true,
        'feed_updated_at' => $now,
    ]);

    $clearedAlert = DrtAlert::factory()->create([
        'external_id' => 'route-920-921-detour',
        'title' => 'Routes 920 and 921 Detour - Oshawa',
        'posted_at' => $twoHoursAgo,
        'when_text' => null,
        'route_text' => null,
        'body_text' => null,
        'details_url' => 'https://www.durhamregiontransit.com/en/news/route-920-921-detour.aspx',
        'is_active' => false,
        'feed_updated_at' => $twoHoursAgo,
    ]);

    logInfo('Created test records', [
        'active_id' => $activeAlert->id,
        'cleared_id' => $clearedAlert->id,
    ]);

    // ── Phase 2A: Provider Registration and Identity ─────────────────
    logInfo('Phase 2A: Verify provider registration and source identity');

    $providers = collect(app()->tagged('alerts.select-providers'));
    $providerClasses = $providers->map(fn ($p) => get_class($p))->values()->all();

    assertTrue(
        in_array(DrtAlertSelectProvider::class, $providerClasses, true),
        'DrtAlertSelectProvider is tagged in alerts.select-providers',
        ['tagged' => $providerClasses]
    );

    $provider = new DrtAlertSelectProvider;
    assertTrue(
        $provider->source() === 'drt',
        'provider source() returns drt',
        ['actual' => $provider->source()]
    );
    assertTrue(
        $provider->source() === AlertSource::Drt->value,
        'provider source matches AlertSource::Drt enum value',
        ['actual' => $provider->source(), 'enum' => AlertSource::Drt->value]
    );

    logInfo('Provider registration verified');

    // ── Phase 2B: Unified Select Columns ─────────────────────────────
    logInfo('Phase 2B: Verify unified select columns and id composition');

    $criteria = new UnifiedAlertsCriteria(status: AlertStatus::All->value);
    $row = $provider->select($criteria)->where('external_id', 'conlin-grandview-detour')->first();

    assertTrue($row !== null, 'provider returns a row for the active alert');
    assertTrue(
        $row->id === 'drt:conlin-grandview-detour',
        'id is prefixed with drt:',
        ['actual' => $row->id]
    );
    assertTrue(
        $row->source === 'drt',
        'source column is drt',
        ['actual' => $row->source]
    );
    assertTrue(
        $row->external_id === 'conlin-grandview-detour',
        'external_id matches',
        ['actual' => $row->external_id]
    );
    assertTrue(
        (bool) $row->is_active === true,
        'is_active is true for active alert',
        ['actual' => $row->is_active]
    );
    assertTrue(
        $row->title === 'Detour on Conlin Road at Grandview',
        'title matches',
        ['actual' => $row->title]
    );
    assertTrue(
        $row->location_name === '900 and 920',
        'location_name maps from route_text',
        ['actual' => $row->location_name]
    );
    assertTrue(
        $row->lat === null,
        'lat is NULL',
        ['actual' => $row->lat]
    );
    assertTrue(
        $row->lng === null,
        'lng is NULL',
        ['actual' => $row->lng]
    );

    $parsedTs = CarbonImmutable::parse($row->timestamp);
    assertTrue(
        $parsedTs->startOfSecond()->eq($now->startOfSecond()),
        'timestamp maps from posted_at',
        ['row_ts' => $parsedTs->toIso8601String(), 'expected' => $now->toIso8601String()]
    );

    logInfo('Unified select columns verified');

    // ── Phase 2C: Meta JSON Shape ────────────────────────────────────
    logInfo('Phase 2C: Verify meta JSON shape');

    $meta = UnifiedAlertMapper::decodeMeta($row->meta);

    assertTrue(
        array_key_exists('details_url', $meta),
        'meta contains details_url key'
    );
    assertTrue(
        array_key_exists('when_text', $meta),
        'meta contains when_text key'
    );
    assertTrue(
        array_key_exists('route_text', $meta),
        'meta contains route_text key'
    );
    assertTrue(
        array_key_exists('body_text', $meta),
        'meta contains body_text key'
    );
    assertTrue(
        array_key_exists('feed_updated_at', $meta),
        'meta contains feed_updated_at key'
    );
    assertTrue(
        array_key_exists('posted_at', $meta),
        'meta contains posted_at key'
    );
    assertTrue(
        $meta['details_url'] === 'https://www.durhamregiontransit.com/en/news/conlin-grandview-detour.aspx',
        'meta details_url value matches',
        ['actual' => $meta['details_url']]
    );
    assertTrue(
        $meta['when_text'] === 'Effective April 3, 2026 until further notice',
        'meta when_text value matches',
        ['actual' => $meta['when_text']]
    );
    assertTrue(
        $meta['route_text'] === '900 and 920',
        'meta route_text value matches',
        ['actual' => $meta['route_text']]
    );
    assertTrue(
        str_contains((string) $meta['body_text'], 'Conlin Road'),
        'meta body_text contains expected content',
        ['actual' => $meta['body_text']]
    );

    // Verify nullable fields on the cleared alert
    $nullRow = $provider->select($criteria)->where('external_id', 'route-920-921-detour')->first();
    assertTrue($nullRow !== null, 'provider returns a row for the cleared alert');
    $nullMeta = UnifiedAlertMapper::decodeMeta($nullRow->meta);

    assertTrue(
        $nullMeta['when_text'] === null,
        'meta is null-safe for when_text',
        ['actual' => $nullMeta['when_text']]
    );
    assertTrue(
        $nullMeta['route_text'] === null,
        'meta is null-safe for route_text',
        ['actual' => $nullMeta['route_text']]
    );
    assertTrue(
        $nullMeta['body_text'] === null,
        'meta is null-safe for body_text',
        ['actual' => $nullMeta['body_text']]
    );

    logInfo('Meta JSON shape verified');

    // ── Phase 2D: Criteria Filtering ─────────────────────────────────
    logInfo('Phase 2D: Verify criteria filter behavior');

    $activeCount = $provider->select(new UnifiedAlertsCriteria(
        status: AlertStatus::Active->value,
    ))->count();
    assertTrue($activeCount === 1, 'active filter returns 1 active DRT alert', ['count' => $activeCount]);

    $clearedCount = $provider->select(new UnifiedAlertsCriteria(
        status: AlertStatus::Cleared->value,
    ))->count();
    assertTrue($clearedCount === 1, 'cleared filter returns 1 inactive DRT alert', ['count' => $clearedCount]);

    $allCount = $provider->select(new UnifiedAlertsCriteria(
        status: AlertStatus::All->value,
    ))->count();
    assertTrue($allCount === 2, 'all filter returns both DRT alerts', ['count' => $allCount]);

    $sinceCount = $provider->select(new UnifiedAlertsCriteria(
        status: AlertStatus::All->value,
        since: '1h',
    ))->count();
    assertTrue($sinceCount === 1, 'since=1h keeps only the recent alert', ['count' => $sinceCount]);

    $matchSourceCount = $provider->select(new UnifiedAlertsCriteria(
        status: AlertStatus::All->value,
        source: 'drt',
    ))->count();
    assertTrue($matchSourceCount === 2, 'source=drt returns both DRT alerts', ['count' => $matchSourceCount]);

    $mismatchSourceCount = $provider->select(new UnifiedAlertsCriteria(
        status: AlertStatus::All->value,
        source: 'fire',
    ))->count();
    assertTrue($mismatchSourceCount === 0, 'source=fire returns 0 rows from DRT provider', ['count' => $mismatchSourceCount]);

    logInfo('Criteria filters verified');

    // ── Phase 2E: UnifiedAlertsQuery Integration ─────────────────────
    logInfo('Phase 2E: Verify UnifiedAlertsQuery integration');

    $query = app(UnifiedAlertsQuery::class);

    // 2E-1: source=drt returns both DRT alerts
    $drtResults = $query->paginate(new UnifiedAlertsCriteria(
        status: AlertStatus::All->value,
        source: 'drt',
        perPage: 50,
    ));
    assertTrue(
        $drtResults->total() === 2,
        'unified query returns 2 DRT rows when source=drt',
        ['total' => $drtResults->total()]
    );
    assertTrue(
        $drtResults->items()[0]->source === 'drt',
        'first unified result has drt source',
        ['source' => $drtResults->items()[0]->source]
    );
    assertTrue(
        str_starts_with($drtResults->items()[0]->id, 'drt:'),
        'first unified result id is drt-prefixed',
        ['id' => $drtResults->items()[0]->id]
    );

    // 2E-2: source=fire returns no DRT alerts
    $fireResults = $query->paginate(new UnifiedAlertsCriteria(
        status: AlertStatus::All->value,
        source: 'fire',
        perPage: 50,
    ));
    $fireDrtCount = collect($fireResults->items())
        ->filter(fn ($item) => $item->source === 'drt')
        ->count();
    assertTrue(
        $fireDrtCount === 0,
        'unified query with source=fire contains zero DRT rows',
        ['drt_count' => $fireDrtCount]
    );

    // 2E-3: no source filter includes DRT alerts in the union
    $allResults = $query->paginate(new UnifiedAlertsCriteria(
        status: AlertStatus::All->value,
        perPage: 50,
    ));
    $allDrtCount = collect($allResults->items())
        ->filter(fn ($item) => $item->source === 'drt')
        ->count();
    assertTrue(
        $allDrtCount === 2,
        'unified query with no source filter includes both DRT rows',
        ['drt_count' => $allDrtCount, 'total' => $allResults->total()]
    );

    // 2E-4: text search on SQLite (skip on MySQL/MariaDB)
    $driver = DB::getDriverName();
    if ($driver !== 'mysql' && $driver !== 'mariadb') {
        $searchResults = $query->paginate(new UnifiedAlertsCriteria(
            status: AlertStatus::All->value,
            source: 'drt',
            query: 'Conlin',
            perPage: 50,
        ));
        assertTrue(
            $searchResults->total() === 1,
            'query=Conlin finds expected DRT alert by title',
            ['total' => $searchResults->total()]
        );
        assertTrue(
            $searchResults->items()[0]->externalId === 'conlin-grandview-detour',
            'search returns the correct DRT alert',
            ['externalId' => $searchResults->items()[0]->externalId]
        );
    } else {
        logInfo('Skipping text-search assertion on MySQL driver (FULLTEXT not in scope for phase 5).', [
            'driver' => $driver,
        ]);
    }

    logInfo('UnifiedAlertsQuery integration verified');

    // ── Phase 3: Cleanup ─────────────────────────────────────────────
    logInfo('Phase 3: Cleanup');

    DB::rollBack();
    logInfo('Database transaction rolled back');
    logInfo("=== Manual Test Completed: {$testRunId} ===");

    echo "\n✓ Manual verification passed. Log: {$logFile}\n";
    exit(0);
} catch (Throwable $e) {
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
