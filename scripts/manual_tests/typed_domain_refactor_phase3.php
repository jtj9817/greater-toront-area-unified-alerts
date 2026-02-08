<?php

/**
 * Manual Test Script for Typed Domain Refactor (Phase 3: Logic Migration)
 * Generated: 2026-02-07
 *
 * Purpose:
 * - Verify Phase 3 frontend logic migration is in place:
 *   - AlertService is a thin facade for mapping/search.
 *   - Source-specific presentation logic lives in domain modules.
 *   - DomainAlert -> AlertItem mapping uses dedicated view mapper layer.
 *   - Transit category alias still includes GO Transit.
 * - Optionally run frontend command gates (Vitest + TypeScript).
 *
 * Usage (local PHP):
 *   php scripts/manual_tests/typed_domain_refactor_phase3.php
 *
 * Usage (Sail):
 *   ./vendor/bin/sail php scripts/manual_tests/typed_domain_refactor_phase3.php
 *
 * Environment variables:
 *   RUN_COMMAND_GATES=1   Run Vitest and TypeScript command gates (default: 1)
 *   RUN_COMMAND_GATES=0   Skip command gates and run structural verification only
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

$testRunId = 'typed_domain_refactor_phase3_'.Carbon::now()->format('Y_m_d_His');
$logFile = storage_path("logs/manual_tests/{$testRunId}.log");
$logFileRelative = "storage/logs/manual_tests/{$testRunId}.log";
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

function readFileStrict(string $relativePath): string
{
    $fullPath = base_path($relativePath);
    $contents = file_get_contents($fullPath);
    assertTrue($contents !== false, "read file: {$relativePath}", ['path' => $fullPath]);

    return $contents === false ? '' : $contents;
}

function runCommandGate(string $label, string $command): void
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
}

$runCommandGates = getenv('RUN_COMMAND_GATES');
$shouldRunCommandGates = $runCommandGates === false ? true : $runCommandGates !== '0';

$exitCode = 0;

try {
    logInfo('=== Starting Manual Test: Typed Domain Refactor Phase 3 (Logic Migration) ===');
    logInfo('Boot context', [
        'app_env' => app()->environment(),
        'run_command_gates' => $shouldRunCommandGates,
    ]);

    logInfo('Phase 1: Setup and file presence verification');
    $requiredFiles = [
        'resources/js/features/gta-alerts/services/AlertService.ts',
        'resources/js/features/gta-alerts/domain/alerts/index.ts',
        'resources/js/features/gta-alerts/domain/alerts/fire/presentation.ts',
        'resources/js/features/gta-alerts/domain/alerts/police/presentation.ts',
        'resources/js/features/gta-alerts/domain/alerts/transit/presentation.ts',
        'resources/js/features/gta-alerts/domain/alerts/view/mapDomainAlertToAlertItem.ts',
        'resources/js/features/gta-alerts/domain/alerts/view/presentationStyles.ts',
        'resources/js/features/gta-alerts/domain/alerts/fire/presentation.test.ts',
        'resources/js/features/gta-alerts/domain/alerts/police/presentation.test.ts',
        'resources/js/features/gta-alerts/domain/alerts/transit/presentation.test.ts',
        'resources/js/features/gta-alerts/domain/alerts/view/mapDomainAlertToAlertItem.test.ts',
    ];

    foreach ($requiredFiles as $path) {
        assertFileExists($path);
    }

    logInfo('Phase 2: Structural verification for migrated logic boundaries');
    $alertService = readFileStrict('resources/js/features/gta-alerts/services/AlertService.ts');
    assertContains(
        $alertService,
        "import { fromResource, mapDomainAlertToAlertItem } from '../domain/alerts';",
        'AlertService imports domain mapper facade'
    );
    assertContains(
        $alertService,
        'return mapDomainAlertToAlertItem(domainAlert);',
        'AlertService delegates mapping to domain view layer'
    );
    assertContains(
        $alertService,
        "transit: ['transit', 'go_transit']",
        'AlertService keeps transit category alias for GO Transit'
    );

    assertNotContains($alertService, 'private static mapDomainAlertToAlertItem(', 'AlertService no longer owns mapping composition');
    assertNotContains($alertService, 'private static getAlertItemType(', 'AlertService no longer owns type derivation logic');
    assertNotContains($alertService, 'private static getSeverity(', 'AlertService no longer owns severity derivation logic');
    assertNotContains($alertService, 'private static getDescriptionAndMetadata(', 'AlertService no longer owns description/metadata logic');
    assertNotContains($alertService, 'private static getIconForType(', 'AlertService no longer owns icon derivation logic');
    assertNotContains($alertService, 'private static getAccentColorForType(', 'AlertService no longer owns accent color logic');
    assertNotContains($alertService, 'private static getIconColorForType(', 'AlertService no longer owns icon color logic');

    $domainIndex = readFileStrict('resources/js/features/gta-alerts/domain/alerts/index.ts');
    assertContains(
        $domainIndex,
        "export { mapDomainAlertToAlertItem } from './view';",
        'domain barrel exports mapDomainAlertToAlertItem'
    );

    $viewMapper = readFileStrict('resources/js/features/gta-alerts/domain/alerts/view/mapDomainAlertToAlertItem.ts');
    assertContains($viewMapper, 'switch (alert.kind)', 'view mapper switches on discriminated union kind');
    assertContains($viewMapper, "case 'fire'", 'view mapper handles fire');
    assertContains($viewMapper, "case 'police'", 'view mapper handles police');
    assertContains($viewMapper, "case 'transit'", 'view mapper handles transit');
    assertContains($viewMapper, "case 'go_transit'", 'view mapper handles go_transit');

    $firePresentation = readFileStrict('resources/js/features/gta-alerts/domain/alerts/fire/presentation.ts');
    assertContains($firePresentation, 'deriveFirePresentationType', 'fire presentation derives UI type');
    assertContains($firePresentation, "return 'hazard';", 'fire presentation supports derived hazard type');
    assertContains($firePresentation, "return 'medical';", 'fire presentation supports derived medical type');
    assertContains($firePresentation, 'deriveFireSeverity', 'fire presentation derives severity');
    assertContains($firePresentation, 'buildFireDescriptionAndMetadata', 'fire presentation builds description and metadata');

    $policePresentation = readFileStrict('resources/js/features/gta-alerts/domain/alerts/police/presentation.ts');
    assertContains($policePresentation, 'derivePoliceSeverity', 'police presentation derives severity');
    assertContains($policePresentation, 'buildPoliceDescriptionAndMetadata', 'police presentation builds description and metadata');

    $transitPresentation = readFileStrict('resources/js/features/gta-alerts/domain/alerts/transit/presentation.ts');
    assertContains($transitPresentation, 'deriveTtcSeverity', 'transit presentation derives TTC severity');
    assertContains($transitPresentation, 'deriveGoTransitSeverity', 'transit presentation derives GO severity');
    assertContains($transitPresentation, 'buildTtcDescriptionAndMetadata', 'transit presentation builds TTC description and metadata');
    assertContains($transitPresentation, 'buildGoTransitDescriptionAndMetadata', 'transit presentation builds GO description and metadata');

    $domainTypes = readFileStrict('resources/js/features/gta-alerts/domain/alerts/types.ts');
    assertNotContains($domainTypes, "'hazard'", 'DomainAlert kind remains source-level (no hazard kind)');
    assertNotContains($domainTypes, "'medical'", 'DomainAlert kind remains source-level (no medical kind)');

    logInfo('Phase 2b: Test file presence and expected suites');
    $viewMapperTests = readFileStrict('resources/js/features/gta-alerts/domain/alerts/view/mapDomainAlertToAlertItem.test.ts');
    assertContains($viewMapperTests, "describe('mapDomainAlertToAlertItem'", 'view mapper test suite exists');

    $fireTests = readFileStrict('resources/js/features/gta-alerts/domain/alerts/fire/presentation.test.ts');
    assertContains($fireTests, "describe('fire presentation'", 'fire presentation test suite exists');

    $policeTests = readFileStrict('resources/js/features/gta-alerts/domain/alerts/police/presentation.test.ts');
    assertContains($policeTests, "describe('police presentation'", 'police presentation test suite exists');

    $transitTests = readFileStrict('resources/js/features/gta-alerts/domain/alerts/transit/presentation.test.ts');
    assertContains($transitTests, "describe('transit presentation'", 'transit presentation test suite exists');

    logInfo('Phase 3: Command gates');
    if ($shouldRunCommandGates) {
        runCommandGate(
            'Phase 3 targeted frontend tests',
            'pnpm exec vitest run '.
            'resources/js/features/gta-alerts/services/AlertService.test.ts '.
            'resources/js/features/gta-alerts/domain/alerts/fromResource.contract.test.ts '.
            'resources/js/features/gta-alerts/domain/alerts/fire/mapper.test.ts '.
            'resources/js/features/gta-alerts/domain/alerts/police/mapper.test.ts '.
            'resources/js/features/gta-alerts/domain/alerts/transit/ttc/mapper.test.ts '.
            'resources/js/features/gta-alerts/domain/alerts/transit/go/mapper.test.ts '.
            'resources/js/features/gta-alerts/domain/alerts/fire/presentation.test.ts '.
            'resources/js/features/gta-alerts/domain/alerts/police/presentation.test.ts '.
            'resources/js/features/gta-alerts/domain/alerts/transit/presentation.test.ts '.
            'resources/js/features/gta-alerts/domain/alerts/view/mapDomainAlertToAlertItem.test.ts'
        );

        runCommandGate(
            'Phase 3 consumer smoke tests',
            'pnpm exec vitest run '.
            'resources/js/features/gta-alerts/App.test.tsx '.
            'resources/js/features/gta-alerts/components/FeedView.test.tsx '.
            'resources/js/features/gta-alerts/components/AlertCard.test.tsx'
        );

        runCommandGate('TypeScript type check', 'pnpm run types');
    } else {
        logInfo('Skipping command gates (RUN_COMMAND_GATES=0).');
    }

    logInfo('Phase 4: Cleanup');
    logInfo('No database records were created or modified; cleanup is complete.');
    logInfo('=== Manual Test Completed Successfully ===');
} catch (Throwable $e) {
    $exitCode = 1;
    logError('Manual Test Failed', [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
} finally {
    logInfo('=== Test Run Finished ===');

    if ($exitCode === 0) {
        echo "\nResult: PASS\nLogs at: {$logFileRelative}\n";
    } else {
        echo "\nResult: FAIL\nLogs at: {$logFileRelative}\n";
    }

    exit($exitCode);
}
