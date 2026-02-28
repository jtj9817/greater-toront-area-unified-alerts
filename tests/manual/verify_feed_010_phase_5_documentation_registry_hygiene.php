<?php

/**
 * Manual Verification Script: FEED-010 Phase 5 — Documentation + Registry Hygiene
 *
 * Verifies that all documentation files have been updated to reflect PostgreSQL
 * production support, phpunit.pgsql.xml is correctly configured, conductor/tracks.md
 * shows the track as archived, and the track archive directory exists.
 *
 * This script does NOT require database access. All checks are filesystem-only.
 *
 * Usage:
 *   APP_ENV=testing ./vendor/bin/sail php tests/manual/verify_feed_010_phase_5_documentation_registry_hygiene.php
 */

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

if (app()->environment('production')) {
    exit("Error: Cannot run manual tests in production!\n");
}

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

$testRunId = 'feed_010_phase_5_documentation_registry_hygiene_' . Carbon::now()->format('Y_m_d_His');
$logFile = storage_path("logs/manual_tests/{$testRunId}.log");

if (! is_dir(dirname($logFile))) {
    mkdir(dirname($logFile), 0755, true);
}

config(['logging.channels.manual_test' => [
    'driver' => 'single',
    'path' => $logFile,
    'level' => 'debug',
]]);

// ─── Helpers ─────────────────────────────────────────────────────────────────

$passed = 0;
$failed = 0;

function logInfo(string $msg, array $ctx = []): void
{
    Log::channel('manual_test')->info($msg, $ctx);
    echo "[INFO] {$msg}\n";
}

function logError(string $msg, array $ctx = []): void
{
    Log::channel('manual_test')->error($msg, $ctx);
    echo "[ERROR] {$msg}\n";
}

function check(string $label, bool $result, string $detail = ''): void
{
    global $passed, $failed;
    if ($result) {
        $passed++;
        $symbol = '✓';
        $status = 'PASS';
    } else {
        $failed++;
        $symbol = '✗';
        $status = 'FAIL';
    }
    $line = "  [{$status}] {$symbol} {$label}" . ($detail ? " — {$detail}" : '');
    echo $line . "\n";
    Log::channel('manual_test')->info($line);
}

function fileContains(string $filePath, string $needle, bool $caseInsensitive = false): bool
{
    if (! file_exists($filePath)) {
        return false;
    }
    $contents = file_get_contents($filePath);
    if ($caseInsensitive) {
        return stripos($contents, $needle) !== false;
    }

    return str_contains($contents, $needle);
}

// ─── Script Start ─────────────────────────────────────────────────────────────

logInfo("=== FEED-010 Phase 5: Documentation + Registry Hygiene Verification ===");
logInfo("Run ID: {$testRunId}");
logInfo('');

$basePath = dirname(__DIR__, 2);

// ─── Group 1: Documentation Files ─────────────────────────────────────────────

logInfo('Group 1: Documentation Files Exist and Contain PostgreSQL Content');

// 1.1 README.md
$readmePath = "{$basePath}/README.md";
echo "\n  README.md\n";
check('README.md exists', file_exists($readmePath));
check('README.md contains "PostgreSQL"', fileContains($readmePath, 'PostgreSQL', true));
check('README.md references phpunit.pgsql.xml', fileContains($readmePath, 'phpunit.pgsql.xml'));

// 1.2 conductor/tech-stack.md
$techStackPath = "{$basePath}/conductor/tech-stack.md";
echo "\n  conductor/tech-stack.md\n";
check('conductor/tech-stack.md exists', file_exists($techStackPath));
check('tech-stack.md contains "PostgreSQL"', fileContains($techStackPath, 'PostgreSQL', true));
check('tech-stack.md contains "Cross-Driver" section', fileContains($techStackPath, 'Cross-Driver'));

// 1.3 docs/backend/unified-alerts-system.md
$unifiedAlertsPath = "{$basePath}/docs/backend/unified-alerts-system.md";
echo "\n  docs/backend/unified-alerts-system.md\n";
check('unified-alerts-system.md exists', file_exists($unifiedAlertsPath));
check('unified-alerts-system.md contains "PostgreSQL"', fileContains($unifiedAlertsPath, 'PostgreSQL', true));
check('unified-alerts-system.md documents to_tsvector (FTS)', fileContains($unifiedAlertsPath, 'to_tsvector'));
check('unified-alerts-system.md documents ILIKE (substring fallback)', fileContains($unifiedAlertsPath, 'ILIKE'));

// 1.4 docs/plans/hetzner-forge-deployment-preflight.md
$preflightPath = "{$basePath}/docs/plans/hetzner-forge-deployment-preflight.md";
echo "\n  docs/plans/hetzner-forge-deployment-preflight.md\n";
check('hetzner-forge-deployment-preflight.md exists', file_exists($preflightPath));
check('preflight doc contains "PostgreSQL"', fileContains($preflightPath, 'PostgreSQL', true));
check('preflight doc contains pgsql connection config', fileContains($preflightPath, 'DB_CONNECTION=pgsql'));

// ─── Group 2: phpunit.pgsql.xml Correctness ───────────────────────────────────

logInfo('');
logInfo('Group 2: phpunit.pgsql.xml Correctness');

$pgsqlXmlPath = "{$basePath}/phpunit.pgsql.xml";
echo "\n  phpunit.pgsql.xml\n";
check('phpunit.pgsql.xml exists', file_exists($pgsqlXmlPath));
check('phpunit.pgsql.xml defines DB_CONNECTION=pgsql', fileContains($pgsqlXmlPath, 'DB_CONNECTION') && fileContains($pgsqlXmlPath, '"pgsql"'));
check('phpunit.pgsql.xml defines DB_HOST', fileContains($pgsqlXmlPath, 'DB_HOST'));
check('phpunit.pgsql.xml defines DB_DATABASE', fileContains($pgsqlXmlPath, 'DB_DATABASE'));
check('phpunit.pgsql.xml defines DB_USERNAME', fileContains($pgsqlXmlPath, 'DB_USERNAME'));
check('phpunit.pgsql.xml defines DB_PORT with 5432', fileContains($pgsqlXmlPath, 'DB_PORT') && fileContains($pgsqlXmlPath, '5432'));

// ─── Group 3: conductor/tracks.md Registry Updated ───────────────────────────

logInfo('');
logInfo('Group 3: conductor/tracks.md Registry Updated');

$tracksPath = "{$basePath}/conductor/tracks.md";
echo "\n  conductor/tracks.md\n";
check('conductor/tracks.md exists', file_exists($tracksPath));

if (file_exists($tracksPath)) {
    $tracksContent = file_get_contents($tracksPath);

    // The track must NOT appear as an uncompleted active item
    $hasUncompleted = str_contains($tracksContent, '[ ] **Track: Abstract Database-Specific SQL Functions');
    check(
        'Active tracks no longer lists feed_010 as uncompleted',
        ! $hasUncompleted,
        $hasUncompleted ? 'Found uncompleted [ ] entry — must be moved to archived' : 'Not found in active (correct)'
    );

    // The track MUST appear in the archived section
    $hasArchived = str_contains($tracksContent, 'feed_010_postgresql_refactoring');
    check(
        'Archived tracks includes feed_010_postgresql_refactoring',
        $hasArchived,
        $hasArchived ? 'Found in archive (correct)' : 'Not found in archive'
    );
} else {
    check('Active tracks no longer lists feed_010 as uncompleted', false, 'File does not exist');
    check('Archived tracks includes feed_010_postgresql_refactoring', false, 'File does not exist');
}

// ─── Group 4: Track Archive Exists ────────────────────────────────────────────

logInfo('');
logInfo('Group 4: Track Archive Directory Exists');

$archiveDir = "{$basePath}/conductor/archive/feed_010_postgresql_refactoring_20260227";
echo "\n  conductor/archive/feed_010_postgresql_refactoring_20260227/\n";
check('Archive directory exists', is_dir($archiveDir));
check('Archive contains plan.md', file_exists("{$archiveDir}/plan.md"));
check('Archive contains spec.md', file_exists("{$archiveDir}/spec.md"));

// ─── Summary ─────────────────────────────────────────────────────────────────

$total = $passed + $failed;
logInfo('');
logInfo("=== Summary ===");
echo "\n";
echo "  Results: {$passed}/{$total} checks passed";
if ($failed > 0) {
    echo " ({$failed} FAILED)";
}
echo "\n";
echo "  Log: {$logFile}\n";

if ($failed > 0) {
    echo "\n  RESULT: FAIL — {$failed} check(s) did not pass.\n";
    Log::channel('manual_test')->error("Phase 5 verification FAILED: {$failed}/{$total} checks failed.");
    exit(1);
}

echo "\n  RESULT: PASS — All {$total} checks passed.\n";
Log::channel('manual_test')->info("Phase 5 verification PASSED: {$total}/{$total} checks passed.");
exit(0);
