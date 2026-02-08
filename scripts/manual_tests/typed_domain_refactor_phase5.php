<?php

/**
 * Manual Test Script for Typed Domain Refactor (Phase 5: Quality & Documentation)
 * Generated: 2026-02-07
 *
 * Purpose:
 * - Verify legacy AlertItem cleanup and DomainAlert/AlertPresentation boundary shape.
 * - Execute quality command gates for backend/frontend.
 * - Enforce targeted frontend coverage >= 90% for Phase 5 runtime modules.
 *
 * Usage (local PHP):
 *   php scripts/manual_tests/typed_domain_refactor_phase5.php
 *
 * Usage (Sail):
 *   ./vendor/bin/sail php scripts/manual_tests/typed_domain_refactor_phase5.php
 *
 * Environment variables:
 *   RUN_COMMAND_GATES=1   Run command gates (default: 1)
 *   RUN_COMMAND_GATES=0   Skip command gates and run structural checks only
 *   SKIP_COVERAGE=1       Skip coverage gate subset while command gates are enabled
 */

require dirname(__DIR__, 2).'/vendor/autoload.php';

use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

if (getenv('APP_ENV') === false || getenv('APP_ENV') === '') {
    putenv('APP_ENV=testing');
    $_ENV['APP_ENV'] = 'testing';
    $_SERVER['APP_ENV'] = 'testing';
}

$manualTestEnv = getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? null);
if ($manualTestEnv === 'testing' && (getenv('APP_KEY') === false || getenv('APP_KEY') === '')) {
    $fallbackAppKey = 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';
    putenv("APP_KEY={$fallbackAppKey}");
    $_ENV['APP_KEY'] = $fallbackAppKey;
    $_SERVER['APP_KEY'] = $fallbackAppKey;
}

$app = require_once dirname(__DIR__, 2).'/bootstrap/app.php';
$kernel = $app->make(ConsoleKernel::class);
$kernel->bootstrap();

if (app()->environment('production')) {
    exit("Error: Cannot run manual tests in production.\n");
}

if (function_exists('posix_geteuid') && posix_geteuid() === 0 && getenv('ALLOW_ROOT_MANUAL_TESTS') !== '1') {
    fwrite(STDERR, "Error: Do not run manual tests as root. Use `./vendor/bin/sail php ...`.\n");
    fwrite(STDERR, "If absolutely needed, set ALLOW_ROOT_MANUAL_TESTS=1.\n");
    exit(1);
}

umask(002);

$testRunId = 'typed_domain_refactor_phase5_'.Carbon::now()->format('Y_m_d_His');
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

function logInfo(string $message, array $context = []): void
{
    Log::channel('manual_test')->info($message, $context);
    $suffix = $context === [] ? '' : ' '.json_encode($context, JSON_UNESCAPED_SLASHES);
    echo "[INFO] {$message}{$suffix}\n";
}

function logError(string $message, array $context = []): void
{
    Log::channel('manual_test')->error($message, $context);
    $suffix = $context === [] ? '' : ' '.json_encode($context, JSON_UNESCAPED_SLASHES);
    echo "[ERROR] {$message}{$suffix}\n";
}

function assertTrue(bool $condition, string $label, array $context = []): void
{
    if (! $condition) {
        $message = "Assertion failed: {$label}.";
        logError($message, $context);
        throw new RuntimeException($message);
    }

    logInfo("Assertion passed: {$label}");
}

function assertContains(string $haystack, string $needle, string $label): void
{
    assertTrue(str_contains($haystack, $needle), $label, ['needle' => $needle]);
}

function assertNotContains(string $haystack, string $needle, string $label): void
{
    assertTrue(! str_contains($haystack, $needle), $label, ['needle' => $needle]);
}

function assertFileExists(string $relativePath): void
{
    $fullPath = base_path($relativePath);
    assertTrue(file_exists($fullPath), "file exists: {$relativePath}", ['path' => $fullPath]);
}

function assertFileMissing(string $relativePath): void
{
    $fullPath = base_path($relativePath);
    assertTrue(! file_exists($fullPath), "file missing: {$relativePath}", ['path' => $fullPath]);
}

function readFileStrict(string $relativePath): string
{
    $fullPath = base_path($relativePath);
    $contents = file_get_contents($fullPath);
    assertTrue($contents !== false, "read file: {$relativePath}", ['path' => $fullPath]);

    return $contents === false ? '' : $contents;
}

function runCommandGate(string $label, string $command): string
{
    logInfo("Running command gate: {$label}", ['command' => $command]);

    $process = new Process(['bash', '-lc', $command], base_path());
    $process->setTimeout(null);

    $outputBuffer = '';
    $process->run(function (string $type, string $output) use (&$outputBuffer): void {
        $outputBuffer .= $output;
        echo $output;
    });

    if (! $process->isSuccessful()) {
        logError("Command gate failed: {$label}", [
            'exit_code' => $process->getExitCode(),
            'output_tail' => mb_substr($outputBuffer, -5000),
        ]);
        throw new RuntimeException("Command gate failed: {$label}");
    }

    logInfo("Command gate passed: {$label}", ['exit_code' => $process->getExitCode()]);

    return $outputBuffer;
}

function normalizePath(string $path): string
{
    return str_replace('\\\\', '/', $path);
}

/**
 * @param  array<string,mixed>  $coverageEntry
 * @return array{statements: float, branches: float, functions: float, lines: float}
 */
function calculateCoveragePercentages(array $coverageEntry): array
{
    $statementHits = $coverageEntry['s'] ?? [];
    $functionHits = $coverageEntry['f'] ?? [];
    $branchHits = $coverageEntry['b'] ?? [];
    $statementMap = $coverageEntry['statementMap'] ?? [];

    $statementTotal = count($statementHits);
    $statementCovered = count(array_filter($statementHits, fn ($hits) => (int) $hits > 0));

    $functionTotal = count($functionHits);
    $functionCovered = count(array_filter($functionHits, fn ($hits) => (int) $hits > 0));

    $branchTotal = 0;
    $branchCovered = 0;
    foreach ($branchHits as $hitsPerBranch) {
        if (! is_array($hitsPerBranch)) {
            continue;
        }

        foreach ($hitsPerBranch as $hits) {
            $branchTotal++;
            if ((int) $hits > 0) {
                $branchCovered++;
            }
        }
    }

    $lineTotals = [];
    foreach ($statementMap as $key => $statement) {
        if (! is_array($statement) || ! isset($statement['start']['line'])) {
            continue;
        }

        $line = (int) $statement['start']['line'];
        $lineTotals[$line] = ($lineTotals[$line] ?? false) || ((int) ($statementHits[$key] ?? 0) > 0);
    }

    $lineTotal = count($lineTotals);
    $lineCovered = count(array_filter($lineTotals, fn (bool $covered) => $covered));

    $pct = static fn (int $covered, int $total): float => $total === 0 ? 100.0 : round(($covered / $total) * 100, 2);

    return [
        'statements' => $pct($statementCovered, $statementTotal),
        'branches' => $pct($branchCovered, $branchTotal),
        'functions' => $pct($functionCovered, $functionTotal),
        'lines' => $pct($lineCovered, $lineTotal),
    ];
}

/**
 * @param  array<string,mixed>  $coverageData
 * @return array<string,mixed>|null
 */
function findCoverageEntryForTarget(array $coverageData, string $targetPath): ?array
{
    $normalizedTarget = normalizePath($targetPath);

    foreach ($coverageData as $path => $entry) {
        $normalizedPath = normalizePath((string) $path);
        if (str_ends_with($normalizedPath, $normalizedTarget) && is_array($entry)) {
            return $entry;
        }
    }

    return null;
}

$runCommandGates = getenv('RUN_COMMAND_GATES');
$shouldRunCommandGates = $runCommandGates === false ? true : $runCommandGates !== '0';

$exitCode = 0;

try {
    logInfo('=== Starting Manual Test: Typed Domain Refactor Phase 5 (Quality & Documentation) ===');
    logInfo('Boot context', [
        'app_env' => app()->environment(),
        'run_command_gates' => $shouldRunCommandGates,
    ]);

    logInfo('Phase 1: Setup and structural file verification');
    $requiredFiles = [
        'resources/js/features/gta-alerts/services/AlertService.ts',
        'resources/js/features/gta-alerts/domain/alerts/index.ts',
        'resources/js/features/gta-alerts/domain/alerts/resource.ts',
        'resources/js/features/gta-alerts/domain/alerts/view/types.ts',
        'resources/js/features/gta-alerts/domain/alerts/view/mapDomainAlertToPresentation.ts',
        'resources/js/features/gta-alerts/domain/alerts/view/mapDomainAlertToPresentation.test.ts',
        'docs/frontend/types.md',
        'docs/frontend/alert-service.md',
        'README.md',
        'CLAUDE.md',
    ];

    foreach ($requiredFiles as $path) {
        assertFileExists($path);
    }

    assertFileMissing(
        'resources/js/features/gta-alerts/types.ts',
    );

    $domainIndex = readFileStrict('resources/js/features/gta-alerts/domain/alerts/index.ts');
    assertContains($domainIndex, 'mapDomainAlertToPresentation', 'domain barrel exports mapDomainAlertToPresentation');
    assertContains($domainIndex, 'UnifiedAlertResource', 'domain barrel exports UnifiedAlertResource type');

    $serviceFile = readFileStrict('resources/js/features/gta-alerts/services/AlertService.ts');
    assertContains($serviceFile, 'mapUnifiedAlertToDomainAlert', 'AlertService exposes domain mapper');
    assertContains($serviceFile, 'mapUnifiedAlertsToDomainAlerts', 'AlertService exposes domain list mapper');
    assertContains($serviceFile, 'searchDomainAlerts', 'AlertService exposes domain search');
    assertNotContains($serviceFile, 'mapUnifiedAlertToAlertItem', 'AlertService removed legacy single-item mapper');
    assertNotContains($serviceFile, 'mapUnifiedAlertsToAlertItems', 'AlertService removed legacy list mapper');

    $appFile = readFileStrict('resources/js/features/gta-alerts/App.tsx');
    assertContains($appFile, 'type { DomainAlert, UnifiedAlertResource }', 'App consumes domain-exported transport type');

    $typesDoc = readFileStrict('docs/frontend/types.md');
    assertContains($typesDoc, 'DomainAlert', 'types doc references DomainAlert union');
    assertContains($typesDoc, 'AlertPresentation', 'types doc references AlertPresentation view model');
    assertNotContains($typesDoc, 'AlertItem', 'types doc no longer references AlertItem');

    $legacyScanOutput = runCommandGate(
        'Legacy frontend symbol scan',
        "rg -n '\\bAlertItem\\b|mapUnifiedAlertToAlertItem|mapUnifiedAlertsToAlertItems' resources/js/features/gta-alerts || true"
    );
    assertTrue(trim($legacyScanOutput) === '', 'Legacy symbol scan returns no frontend matches', [
        'output' => trim($legacyScanOutput),
    ]);

    logInfo('Phase 2: Command gates');
    if ($shouldRunCommandGates) {
        runCommandGate(
            'Backend full test suite (Sail)',
            'CI=true ./vendor/bin/sail artisan test'
        );

        runCommandGate('Frontend full test suite', 'pnpm test');

        runCommandGate('Backend lint (Pint via Sail)', './vendor/bin/sail artisan pint --test');
        runCommandGate('Frontend lint', 'pnpm run lint:check');
        runCommandGate('Frontend typecheck', 'pnpm run types');
        runCommandGate('Frontend production build (Sail)', './vendor/bin/sail pnpm run build');

        if (getenv('SKIP_COVERAGE') === '1') {
            logInfo('Skipping coverage checks because SKIP_COVERAGE=1.');
        } else {
            runCommandGate('Frontend coverage', 'pnpm exec vitest run --coverage');

            $coverageFile = base_path('coverage/coverage-final.json');
            assertTrue(file_exists($coverageFile), 'coverage-final.json exists', ['path' => $coverageFile]);

            $coverageContents = file_get_contents($coverageFile);
            assertTrue($coverageContents !== false, 'read coverage-final.json');

            $coverageData = json_decode($coverageContents ?: '{}', true);
            assertTrue(is_array($coverageData), 'coverage-final.json decoded to object');

            $coverageTargets = [
                'resources/js/features/gta-alerts/services/AlertService.ts',
                'resources/js/features/gta-alerts/domain/alerts/fire/presentation.ts',
                'resources/js/features/gta-alerts/domain/alerts/police/presentation.ts',
                'resources/js/features/gta-alerts/domain/alerts/transit/presentation.ts',
                'resources/js/features/gta-alerts/domain/alerts/view/mapDomainAlertToPresentation.ts',
                'resources/js/features/gta-alerts/domain/alerts/view/presentationStyles.ts',
            ];

            foreach ($coverageTargets as $target) {
                $entry = findCoverageEntryForTarget($coverageData, $target);
                assertTrue($entry !== null, 'coverage entry present for target', ['target' => $target]);

                $percentages = calculateCoveragePercentages($entry);
                logInfo('Coverage summary', ['target' => $target, 'metrics' => $percentages]);

                assertTrue($percentages['statements'] >= 90.0, 'coverage statements >= 90%', [
                    'target' => $target,
                    'actual' => $percentages['statements'],
                ]);
                assertTrue($percentages['lines'] >= 90.0, 'coverage lines >= 90%', [
                    'target' => $target,
                    'actual' => $percentages['lines'],
                ]);
            }
        }
    } else {
        logInfo('RUN_COMMAND_GATES=0 - skipping command gate execution.');
    }

    logInfo('=== Manual Test Completed Successfully: Typed Domain Refactor Phase 5 ===');
    logInfo('Log file', ['path' => $logFile]);
} catch (Throwable $e) {
    $exitCode = 1;
    logError('Manual test failed', [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
} finally {
    if (isset($kernel)) {
        $kernel->terminate(request(), response());
    }
}

exit($exitCode);
