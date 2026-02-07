<?php

/**
 * Manual Test: Frontend Typed Alert Domain Refactor - Phase 1 Foundation
 * Generated: 2026-02-07
 * Purpose: Verify Phase 1 deliverables exist and match backend transport/meta
 * contracts:
 * - Zod dependency present
 * - Domain alert schemas + types exist (fire/police/transit/go_transit)
 * - Canonical fromResource() mapper exists and uses safeParse + log/discard
 * - Schema fields align with backend provider meta contracts
 *
 * Optional command gates:
 * - RUN_FRONTEND_GATES=1 to execute TypeScript compile checks (tsc --noEmit)
 */

require __DIR__.'/../../vendor/autoload.php';

// Default manual verification runs to testing so Laravel loads `.env.testing`.
// Preserve an explicitly provided APP_ENV value if the caller set one.
if (getenv('APP_ENV') === false || getenv('APP_ENV') === '') {
    putenv('APP_ENV=testing');
    $_ENV['APP_ENV'] = 'testing';
    $_SERVER['APP_ENV'] = 'testing';
}

// Some manual test environments do not provide APP_KEY in `.env.testing`.
// Use a deterministic testing-only fallback so middleware/session bootstrapping
// does not fail when booting the application.
$manualTestEnv = getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? null);
if ($manualTestEnv === 'testing' && (getenv('APP_KEY') === false || getenv('APP_KEY') === '')) {
    $fallbackAppKey = 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';
    putenv("APP_KEY={$fallbackAppKey}");
    $_ENV['APP_KEY'] = $fallbackAppKey;
    $_SERVER['APP_KEY'] = $fallbackAppKey;
}

$app = require_once __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Prevent production execution.
if (app()->environment('production')) {
    exit("Error: Cannot run manual tests in production!\n");
}

if (function_exists('posix_geteuid') && posix_geteuid() === 0 && getenv('ALLOW_ROOT_MANUAL_TESTS') !== '1') {
    fwrite(STDERR, "Error: Do not run manual tests as root. Use `./vendor/bin/sail shell` (or `./vendor/bin/sail php ...`).\n");
    fwrite(STDERR, "If you really need root, re-run with ALLOW_ROOT_MANUAL_TESTS=1 (not recommended).\n");
    exit(1);
}

umask(002);

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

$testRunId = 'typed_domain_refactor_phase_1_foundation_'.Carbon::now()->format('Y_m_d_His');
$logFileRelative = "storage/logs/manual_tests/{$testRunId}.log";
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

if (! @chmod($logFile, 0664)) {
    fwrite(STDERR, "Warning: Failed to set permissions on log file: {$logFile}\n");
}

config(['logging.channels.manual_test' => [
    'driver' => 'single',
    'path' => $logFile,
    'level' => 'debug',
]]);

function logInfo(string $msg, array $ctx = []): void
{
    Log::channel('manual_test')->info($msg, $ctx);
    $suffix = $ctx === [] ? '' : ' '.json_encode($ctx, JSON_UNESCAPED_SLASHES);
    echo "[INFO] {$msg}{$suffix}\n";
}

function logError(string $msg, array $ctx = []): void
{
    Log::channel('manual_test')->error($msg, $ctx);
    $suffix = $ctx === [] ? '' : ' '.json_encode($ctx, JSON_UNESCAPED_SLASHES);
    echo "[ERROR] {$msg}{$suffix}\n";
}

function assertTrue(bool $condition, string $label, array $ctx = []): void
{
    if (! $condition) {
        $message = "Assertion failed: {$label}.";
        logError($message, $ctx);
        throw new RuntimeException($message);
    }

    logInfo("Assertion passed: {$label}");
}

function assertContains(string $haystack, string $needle, string $label): void
{
    assertTrue(str_contains($haystack, $needle), $label, [
        'needle' => $needle,
    ]);
}

function assertMatches(string $haystack, string $pattern, string $label): void
{
    $ok = @preg_match($pattern, $haystack) === 1;
    assertTrue($ok, $label, ['pattern' => $pattern]);
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

    $buffer = '';

    $process->run(function (string $type, string $output) use (&$buffer): void {
        $buffer .= $output;
        echo $output;
    });

    if (! $process->isSuccessful()) {
        logError("Command gate failed: {$label}", [
            'exit_code' => $process->getExitCode(),
            'output_tail' => mb_substr($buffer, -5000),
        ]);

        throw new RuntimeException("Command gate failed: {$label}");
    }

    logInfo("Command gate passed: {$label}", ['exit_code' => $process->getExitCode()]);
}

$exitCode = 0;

try {
    logInfo('=== Starting Manual Test: Typed Domain Refactor Phase 1 (Foundation) ===');
    logInfo('Boot context', ['app_env' => app()->environment()]);

    logInfo('Step 1: Zod dependency is declared and installed');
    assertFileExists('package.json');
    $packageJson = json_decode(readFileStrict('package.json'), true);
    assertTrue(is_array($packageJson), 'package.json parses as JSON');

    $deps = is_array($packageJson['dependencies'] ?? null) ? $packageJson['dependencies'] : [];
    assertTrue(is_array($deps), 'package.json has dependencies object');
    assertTrue(array_key_exists('zod', $deps), 'zod dependency declared', ['zod' => $deps['zod'] ?? null]);
    assertFileExists('node_modules/zod/package.json');

    logInfo('Step 2: Domain alerts directory structure exists');
    $requiredFiles = [
        'resources/js/features/gta-alerts/domain/alerts/index.ts',
        'resources/js/features/gta-alerts/domain/alerts/types.ts',
        'resources/js/features/gta-alerts/domain/alerts/resource.ts',
        'resources/js/features/gta-alerts/domain/alerts/fromResource.ts',
        'resources/js/features/gta-alerts/domain/alerts/fire/schema.ts',
        'resources/js/features/gta-alerts/domain/alerts/police/schema.ts',
        'resources/js/features/gta-alerts/domain/alerts/transit/schema.ts',
        'resources/js/features/gta-alerts/domain/alerts/transit/ttc/schema.ts',
        'resources/js/features/gta-alerts/domain/alerts/transit/go/schema.ts',
    ];

    foreach ($requiredFiles as $path) {
        assertFileExists($path);
    }

    logInfo('Step 3: Barrel exports include canonical entry point and key types');
    $indexTs = readFileStrict('resources/js/features/gta-alerts/domain/alerts/index.ts');
    assertContains($indexTs, "export { fromResource } from './fromResource';", 'index exports fromResource');
    assertContains($indexTs, 'export type { DomainAlert, AlertKind }', 'index exports DomainAlert/AlertKind types');
    assertContains($indexTs, 'GoTransitAlertSchema', 'index exports GoTransitAlertSchema');
    assertContains($indexTs, 'TtcTransitAlertSchema', 'index exports TtcTransitAlertSchema');

    logInfo('Step 4: fromResource() uses safeParse and log/discard semantics');
    $fromResourceTs = readFileStrict('resources/js/features/gta-alerts/domain/alerts/fromResource.ts');
    assertContains($fromResourceTs, 'UnifiedAlertResourceSchema.safeParse', 'fromResource validates transport envelope using safeParse');
    assertContains($fromResourceTs, 'console.warn', 'fromResource logs invalid items');
    assertContains($fromResourceTs, 'return null', 'fromResource discards invalid items (returns null)');

    assertContains($fromResourceTs, "case 'fire'", 'fromResource handles fire');
    assertContains($fromResourceTs, "case 'police'", 'fromResource handles police');
    assertContains($fromResourceTs, "case 'transit'", 'fromResource handles transit');
    assertContains($fromResourceTs, "case 'go_transit'", 'fromResource handles go_transit');

    assertContains($fromResourceTs, 'TtcTransitAlertSchema.safeParse', 'fromResource validates transit with TTC schema');
    assertContains($fromResourceTs, 'GoTransitAlertSchema.safeParse', 'fromResource validates GO Transit with GO schema');

    logInfo('Step 5: Zod schemas align with backend provider meta contracts');
    $fireSchema = readFileStrict('resources/js/features/gta-alerts/domain/alerts/fire/schema.ts');
    assertMatches($fireSchema, '/\\balarm_level\\s*:\\s*z\\.number\\(\\)/', 'fire meta: alarm_level is number');
    assertMatches($fireSchema, '/\\bevent_num\\s*:\\s*z\\.string\\(\\)/', 'fire meta: event_num is string (provider emits string external ids)');
    assertMatches($fireSchema, '/\\bunits_dispatched\\s*:\\s*z\\.nullable\\(z\\.string\\(\\)\\)/', 'fire meta: units_dispatched is nullable string');
    assertMatches($fireSchema, '/\\bbeat\\s*:\\s*z\\.nullable\\(z\\.string\\(\\)\\)/', 'fire meta: beat is nullable string');

    $policeSchema = readFileStrict('resources/js/features/gta-alerts/domain/alerts/police/schema.ts');
    assertMatches($policeSchema, '/\\bobject_id\\s*:\\s*z\\.number\\(\\)/', 'police meta: object_id is number');
    assertMatches($policeSchema, '/\\bdivision\\s*:\\s*z\\.nullable\\(z\\.string\\(\\)\\)/', 'police meta: division is nullable string');
    assertMatches($policeSchema, '/\\bcall_type_code\\s*:\\s*z\\.nullable\\(z\\.string\\(\\)\\)/', 'police meta: call_type_code is nullable string');

    $ttcSchema = readFileStrict('resources/js/features/gta-alerts/domain/alerts/transit/ttc/schema.ts');
    assertContains($ttcSchema, 'BaseTransitMetaSchema.extend', 'ttc transit meta extends base transit meta');
    foreach ([
        'route_type',
        'route',
        'severity',
        'effect',
        'source_feed',
        'description',
        'url',
        'cause',
    ] as $key) {
        assertContains($ttcSchema, "{$key}:", "ttc transit meta includes {$key}");
    }

    $goSchema = readFileStrict('resources/js/features/gta-alerts/domain/alerts/transit/go/schema.ts');
    foreach ([
        'service_mode',
        'sub_category',
        'corridor_code',
        'trip_number',
        'delay_duration',
        'line_colour',
        'message_body',
    ] as $key) {
        assertContains($goSchema, "{$key}:", "go transit meta includes {$key}");
    }

    // direction + alert_type are expected via BaseTransitMetaSchema
    $baseTransitSchema = readFileStrict('resources/js/features/gta-alerts/domain/alerts/transit/schema.ts');
    assertContains($baseTransitSchema, 'alert_type', 'base transit meta includes alert_type');
    assertContains($baseTransitSchema, 'direction', 'base transit meta includes direction');

    if (getenv('RUN_FRONTEND_GATES') === '1') {
        runCommandGate(
            'TypeScript domain compile (fromResource)',
            'pnpm -s exec tsc --noEmit --pretty false --module ESNext --target ESNext --moduleResolution bundler --strict true --skipLibCheck true --esModuleInterop true resources/js/features/gta-alerts/domain/alerts/fromResource.ts'
        );
    } else {
        logInfo('Skipping frontend command gate. Set RUN_FRONTEND_GATES=1 to enable.');
    }

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
