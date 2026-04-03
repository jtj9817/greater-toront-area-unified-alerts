<?php

declare(strict_types=1);

require __DIR__.'/../../vendor/autoload.php';

use App\Models\DrtAlert;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

$app = require_once __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

if (app()->environment('production')) {
    fwrite(STDERR, "Error: Cannot run manual tests in production.\n");
    exit(1);
}

$testRunId = 'drt_phase1_'.Carbon::now()->format('Y_m_d_His');
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

$failures = [];

DB::beginTransaction();

try {
    logInfo("=== Starting Manual Test: {$testRunId} ===");
    logInfo('Phase 1: Data Setup');

    $activeAlert = DrtAlert::factory()->create([
        'external_id' => 'manual-phase1-active',
        'is_active' => true,
    ]);
    $inactiveAlert = DrtAlert::factory()->inactive()->create([
        'external_id' => 'manual-phase1-inactive',
        'is_active' => false,
    ]);

    logInfo('Created test records', [
        'active_id' => $activeAlert->id,
        'inactive_id' => $inactiveAlert->id,
    ]);

    logInfo('Phase 2: Execution');

    $activeAlert = DrtAlert::query()->findOrFail($activeAlert->id);

    $requiredColumns = [
        'external_id',
        'title',
        'posted_at',
        'when_text',
        'route_text',
        'details_url',
        'body_text',
        'list_hash',
        'details_fetched_at',
        'is_active',
        'feed_updated_at',
    ];
    $columns = Schema::getColumnListing('drt_alerts');
    foreach ($requiredColumns as $column) {
        if (! in_array($column, $columns, true)) {
            $failures[] = "Missing column: {$column}";
        }
    }

    $indexNames = array_column(Schema::getIndexes('drt_alerts'), 'name');
    foreach ([
        'drt_alerts_external_id_unique',
        'drt_alerts_posted_at_index',
        'drt_alerts_is_active_posted_at_index',
    ] as $indexName) {
        if (! in_array($indexName, $indexNames, true)) {
            $failures[] = "Missing index: {$indexName}";
        }
    }

    $activeCount = DrtAlert::query()->active()->count();
    if ($activeCount !== 1) {
        $failures[] = "scopeActive expected 1 row, got {$activeCount}";
    }

    if (! $activeAlert->posted_at instanceof \DateTimeInterface) {
        $failures[] = 'posted_at was not cast to a datetime instance.';
    }

    if (! is_bool($activeAlert->is_active)) {
        $failures[] = 'is_active was not cast to boolean.';
    }

    try {
        DrtAlert::factory()->create(['external_id' => 'manual-phase1-active']);
        $failures[] = 'Unique constraint on external_id did not reject duplicate value.';
    } catch (QueryException) {
        logInfo('Unique external_id constraint rejected duplicate as expected');
    }

    if ($failures === []) {
        logInfo('Phase 2 completed with all assertions passing');
    } else {
        foreach ($failures as $failure) {
            logError($failure);
        }
        throw new RuntimeException('Manual verification failed with '.count($failures).' issue(s).');
    }
} catch (Throwable $throwable) {
    logError('Manual test failed', [
        'message' => $throwable->getMessage(),
        'file' => $throwable->getFile(),
        'line' => $throwable->getLine(),
    ]);
    throw $throwable;
} finally {
    DB::rollBack();
    logInfo('Phase 3: Cleanup complete via transaction rollback');
    logInfo("=== Test Run Finished: {$testRunId} ===");
    logInfo("Full log file: {$logFile}");
}
