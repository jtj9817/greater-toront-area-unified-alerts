<?php
/**
 * Manual Test: YRT Phase 1 Database + Model
 * Generated: 2026-04-01
 * Purpose: Verify yrt_alerts schema, indexes, model contracts, and factory behavior.
 */

declare(strict_types=1);

require __DIR__.'/../../vendor/autoload.php';

$app = require_once __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\YrtAlert;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

if (app()->environment('production')) {
    fwrite(STDERR, "Error: Cannot run manual tests in production.\n");
    exit(1);
}

$testRunId = 'yrt_phase1_'.Carbon::now()->format('Y_m_d_His');
$logFile = storage_path("logs/manual_tests/{$testRunId}.log");

if (!is_dir(dirname($logFile))) {
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

function assertTrue(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException("Assertion failed: {$message}");
    }
}

function assertContains(array $haystack, string $needle, string $message): void
{
    if (! in_array($needle, $haystack, true)) {
        throw new RuntimeException("Assertion failed: {$message}");
    }
}

try {
    DB::beginTransaction();

    logInfo("=== Starting Manual Test: {$testRunId} ===");
    logInfo('Phase 1: Data Setup');

    // Ensure table exists from migration.
    assertTrue(Schema::hasTable('yrt_alerts'), 'yrt_alerts table exists');

    logInfo('Phase 2: Verification');

    $columns = Schema::getColumnListing('yrt_alerts');
    foreach ([
        'id',
        'external_id',
        'title',
        'posted_at',
        'details_url',
        'description_excerpt',
        'route_text',
        'body_text',
        'list_hash',
        'details_fetched_at',
        'is_active',
        'feed_updated_at',
        'created_at',
        'updated_at',
    ] as $column) {
        assertContains($columns, $column, "column {$column} exists");
    }

    $indexes = Schema::getIndexes('yrt_alerts');
    $indexNames = array_column($indexes, 'name');
    foreach ([
        'yrt_alerts_external_id_unique',
        'yrt_alerts_posted_at_index',
        'yrt_alerts_feed_updated_at_index',
        'yrt_alerts_is_active_posted_at_index',
    ] as $indexName) {
        assertContains($indexNames, $indexName, "index {$indexName} exists");
    }

    $model = new YrtAlert;
    $expectedFillable = [
        'external_id',
        'title',
        'posted_at',
        'details_url',
        'description_excerpt',
        'route_text',
        'body_text',
        'list_hash',
        'details_fetched_at',
        'is_active',
        'feed_updated_at',
    ];
    assertTrue($model->getFillable() === $expectedFillable, 'fillable matches expected contract');

    $casted = new YrtAlert([
        'posted_at' => '2026-04-01 14:00:00',
        'details_fetched_at' => '2026-04-01 14:01:00',
        'feed_updated_at' => '2026-04-01 14:02:00',
        'is_active' => 1,
    ]);

    assertTrue($casted->posted_at instanceof DateTimeInterface, 'posted_at cast works');
    assertTrue($casted->details_fetched_at instanceof DateTimeInterface, 'details_fetched_at cast works');
    assertTrue($casted->feed_updated_at instanceof DateTimeInterface, 'feed_updated_at cast works');
    assertTrue($casted->is_active === true, 'is_active cast works');

    $activeOne = YrtAlert::factory()->create(['is_active' => true]);
    $activeTwo = YrtAlert::factory()->create(['is_active' => true]);
    $inactive = YrtAlert::factory()->inactive()->create();

    $activeCount = YrtAlert::query()->active()->count();
    assertTrue($activeCount === 2, 'scopeActive returns only active records');
    assertTrue($inactive->is_active === false, 'factory inactive state sets false');

    logInfo('Manual verification assertions passed', [
        'active_ids' => [$activeOne->id, $activeTwo->id],
        'inactive_id' => $inactive->id,
        'active_count' => $activeCount,
    ]);

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
