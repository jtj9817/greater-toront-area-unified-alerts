<?php
/**
 * Manual Test Script for Typed Domain Refactor (Phase 4: UI Modernization)
 * Generated: 2026-02-07
 *
 * Purpose:
 * - Verify component modernization cutover to DomainAlert contracts.
 * - Verify AlertDetailsView functional composition and switch(kind) branching.
 * - Optionally run frontend command gates (Vitest + TypeScript).
 *
 * Usage (local PHP):
 *   php scripts/manual_tests/typed_domain_refactor_phase4.php
 *
 * Usage (Sail):
 *   ./vendor/bin/sail php scripts/manual_tests/typed_domain_refactor_phase4.php
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

$testRunId = 'typed_domain_refactor_phase4_'.Carbon::now()->format('Y_m_d_His');
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
    logInfo('=== Starting Manual Test: Typed Domain Refactor Phase 4 (UI Modernization) ===');
    logInfo('Boot context', [
        'app_env' => app()->environment(),
        'run_command_gates' => $shouldRunCommandGates,
    ]);

    logInfo('Phase 1: Setup and file presence verification');
    $requiredFiles = [
        'resources/js/features/gta-alerts/App.tsx',
        'resources/js/features/gta-alerts/services/AlertService.ts',
        'resources/js/features/gta-alerts/components/FeedView.tsx',
        'resources/js/features/gta-alerts/components/AlertCard.tsx',
        'resources/js/features/gta-alerts/components/AlertDetailsView.tsx',
        'resources/js/features/gta-alerts/components/AlertDetailsView.test.tsx',
    ];

    foreach ($requiredFiles as $path) {
        assertFileExists($path);
    }

    logInfo('Phase 2: Structural verification for DomainAlert component cutover');
    $appFile = readFileStrict('resources/js/features/gta-alerts/App.tsx');
    assertContains($appFile, 'mapUnifiedAlertsToDomainAlerts', 'App maps resources to DomainAlert values');

    $serviceFile = readFileStrict('resources/js/features/gta-alerts/services/AlertService.ts');
    assertContains($serviceFile, 'mapUnifiedAlertToDomainAlert', 'AlertService exposes single-resource DomainAlert mapper');
    assertContains($serviceFile, 'mapUnifiedAlertsToDomainAlerts', 'AlertService exposes list DomainAlert mapper');
    assertContains($serviceFile, 'searchDomainAlerts', 'AlertService exposes DomainAlert search');
    assertContains($serviceFile, "transit: ['transit', 'go_transit']", 'AlertService keeps transit alias including GO Transit');

    $feedView = readFileStrict('resources/js/features/gta-alerts/components/FeedView.tsx');
    assertContains($feedView, 'allAlerts: DomainAlert[]', 'FeedView consumes DomainAlert list');
    assertContains($feedView, 'AlertService.searchDomainAlerts', 'FeedView uses DomainAlert search path');

    $alertCard = readFileStrict('resources/js/features/gta-alerts/components/AlertCard.tsx');
    assertContains($alertCard, 'alert: DomainAlert', 'AlertCard consumes DomainAlert prop');
    assertContains($alertCard, 'mapDomainAlertToAlertItem(alert)', 'AlertCard maps domain alert to presentation shape');

    $detailsView = readFileStrict('resources/js/features/gta-alerts/components/AlertDetailsView.tsx');
    assertContains($detailsView, 'switch (alert.kind)', 'AlertDetailsView pattern matches discriminated union kind');
    assertContains($detailsView, "case 'fire'", 'AlertDetailsView handles fire kind');
    assertContains($detailsView, "case 'police'", 'AlertDetailsView handles police kind');
    assertContains($detailsView, "case 'transit'", 'AlertDetailsView handles transit kind');
    assertContains($detailsView, "case 'go_transit'", 'AlertDetailsView handles go_transit kind');
    assertNotContains($detailsView, 'class FireAlertDetail', 'AlertDetailsView no longer uses class inheritance renderer for fire');
    assertNotContains($detailsView, 'extends Component', 'AlertDetailsView no longer extends React Component classes');

    $detailsTests = readFileStrict('resources/js/features/gta-alerts/components/AlertDetailsView.test.tsx');
    assertContains($detailsTests, "describe('AlertDetailsView'", 'AlertDetailsView test suite exists');
    assertContains($detailsTests, 'go_transit kind', 'AlertDetailsView tests include go_transit branch behavior');

    logInfo('Phase 3: Command gates');
    if ($shouldRunCommandGates) {
        runCommandGate(
            'Phase 4 targeted frontend tests',
            'pnpm exec vitest run '.
            'resources/js/features/gta-alerts/App.test.tsx '.
            'resources/js/features/gta-alerts/components/FeedView.test.tsx '.
            'resources/js/features/gta-alerts/components/AlertCard.test.tsx '.
            'resources/js/features/gta-alerts/components/AlertDetailsView.test.tsx '.
            'resources/js/features/gta-alerts/services/AlertService.test.ts'
        );

        runCommandGate('Phase 4 TypeScript check', 'pnpm run types');
    } else {
        logInfo('RUN_COMMAND_GATES=0 - skipping command gate execution.');
    }

    logInfo('=== Manual Test Completed Successfully: Typed Domain Refactor Phase 4 ===');
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
