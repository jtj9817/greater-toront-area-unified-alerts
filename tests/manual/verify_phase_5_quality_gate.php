<?php

/**
 * Manual Test: Phase 5 Quality Gate & Finalization
 * Generated: 2026-02-03
 * Purpose: Manual verification for Phase 5 of the Unified Alerts Architecture track.
 *
 * This script focuses on verifying the user-facing contract and controller behavior:
 * - `GET /` returns the `gta-alerts` Inertia component
 * - `alerts` prop exists and `incidents` prop is absent (hard switch)
 * - Status filtering works (`all`, `active`, `cleared`)
 * - `latest_feed_updated_at` is correctly computed from both feeds
 *
 * Optional: run command-based quality gates by setting `RUN_COMMAND_GATES=1`.
 */

require __DIR__.'/../../vendor/autoload.php';

$app = require_once __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Prevent production execution
if (app()->environment('production')) {
    exit("Error: Cannot run manual tests in production!\n");
}

if (function_exists('posix_geteuid') && posix_geteuid() === 0 && getenv('ALLOW_ROOT_MANUAL_TESTS') !== '1') {
    fwrite(STDERR, "Error: Do not run manual tests as root. Use `./vendor/bin/sail shell` (or `./vendor/bin/sail php ...`).\n");
    fwrite(STDERR, "If you really need root, re-run with ALLOW_ROOT_MANUAL_TESTS=1 (not recommended).\n");
    exit(1);
}

// Manual tests can delete data; only allow the dedicated testing database (no overrides).
$expectedDatabase = 'gta_alerts_testing';
$connection = config('database.default');
$currentDatabase = config("database.connections.{$connection}.database");

if (! app()->environment('testing')) {
    exit("Error: Manual tests must run with APP_ENV=testing. Destructive test operations are disabled outside the testing environment and cannot be overridden.\n");
}

if ($currentDatabase !== $expectedDatabase) {
    exit("Error: Manual tests must use the '{$expectedDatabase}' database (current: {$currentDatabase}). Destructive test operations are disabled and cannot be overridden.\n");
}

umask(002);

use App\Models\FireIncident;
use App\Models\PoliceCall;
use Carbon\Carbon;
use Database\Seeders\UnifiedAlertsTestSeeder;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

$testRunId = 'phase_5_quality_gate_'.Carbon::now()->format('Y_m_d_His');
$logFileRelative = "storage/logs/manual_tests/{$testRunId}.log";
$logFile = storage_path("logs/manual_tests/{$testRunId}.log");

$logDir = dirname($logFile);

if (! is_dir($logDir)) {
    mkdir($logDir, 0775, true);
}

if (! file_exists($logFile)) {
    touch($logFile);
    chmod($logFile, 0664);
}

config(['logging.channels.manual_test' => [
    'driver' => 'single',
    'path' => $logFile,
    'level' => 'debug',
]]);

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

function assertTrue(bool $condition, string $label, array $ctx = []): void
{
    if (! $condition) {
        $message = "Assertion failed: {$label}.";
        logError($message, $ctx);
        throw new \RuntimeException($message);
    }

    logInfo("Assertion passed: {$label}");
}

function assertEqual(mixed $actual, mixed $expected, string $label): void
{
    if ($actual !== $expected) {
        $message = "Assertion failed for {$label}.";
        logError($message, ['expected' => $expected, 'actual' => $actual]);
        throw new \RuntimeException($message);
    }

    logInfo("Assertion passed: {$label}");
}

function runCommand(string $label, string $command): void
{
    logInfo("Running: {$label}", ['command' => $command]);

    $process = new Process(['bash', '-lc', $command], base_path());
    $process->setTimeout(null);

    $outputBuffer = '';

    $process->run(function (string $type, string $output) use (&$outputBuffer): void {
        $outputBuffer .= $output;
        echo $output;
    });

    if (! $process->isSuccessful()) {
        logError("Command failed: {$label}", [
            'exit_code' => $process->getExitCode(),
            'output_tail' => mb_substr($outputBuffer, -5000),
        ]);

        throw new RuntimeException("Phase 5 quality gate failed at: {$label}");
    }

    logInfo("Command succeeded: {$label}", ['exit_code' => $process->getExitCode()]);
}

$exitCode = 0;
$txStarted = false;

try {
    // Manual scripts are usually executed via Sail (MySQL host = "mysql").
    // Provide a clearer error if Docker/Sail isn't running.
    try {
        DB::connection()->getPdo();
    } catch (\Throwable $e) {
        throw new \RuntimeException(
            "Database connection failed. If you're using Sail, ensure Docker is running and execute: ./vendor/bin/sail php tests/manual/verify_phase_5_quality_gate.php",
            previous: $e
        );
    }

    DB::beginTransaction();
    $txStarted = true;

    logInfo('=== Starting Manual Test: Phase 5 Manual Verification ===');

    logInfo('Step 1: Seeding deterministic dataset (UnifiedAlertsTestSeeder)');

    FireIncident::query()->delete();
    PoliceCall::query()->delete();

    Carbon::setTestNow(Carbon::parse('2026-02-03 12:00:00'));
    Artisan::call('db:seed', ['--class' => UnifiedAlertsTestSeeder::class]);

    assertEqual(FireIncident::count(), 4, 'fire_incidents count');
    assertEqual(PoliceCall::count(), 4, 'police_calls count');

    $expectedLatest = Carbon::now()->subMinutes(5)->toIso8601String();

    $httpKernel = app(HttpKernel::class);
    $inertiaMiddleware = app(App\Http\Middleware\HandleInertiaRequests::class);

    $makeInertiaRequest = function (array $query = []) use ($httpKernel, $inertiaMiddleware): array {
        $request = Request::create('/', 'GET', $query, [], [], [
            'HTTP_X_INERTIA' => 'true',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $version = $inertiaMiddleware->version($request);
        if (is_string($version) && $version !== '') {
            $request->headers->set('X-Inertia-Version', $version);
        }

        $response = $httpKernel->handle($request);

        if (method_exists($httpKernel, 'terminate')) {
            $httpKernel->terminate($request, $response);
        }

        if ($response->getStatusCode() === 409) {
            $location = $response->headers->get('X-Inertia-Location') ?? $response->headers->get('Location');

            $suffix = $location ? " Location: {$location}" : '';

            throw new \RuntimeException("Inertia asset version mismatch (409).{$suffix}");
        }

        $payload = json_decode($response->getContent() ?: 'null', true);
        if (! is_array($payload)) {
            $contentType = $response->headers->get('Content-Type');
            $body = $response->getContent() ?? '';
            $bodySnippet = mb_substr(trim($body), 0, 500);

            throw new \RuntimeException(
                'Expected Inertia JSON payload but got non-JSON response.'
                ." status={$response->getStatusCode()}"
                .($contentType ? " content_type={$contentType}" : '')
                .($bodySnippet !== '' ? " body_snippet={$bodySnippet}" : '')
            );
        }

        return $payload;
    };

    $extractAlertIds = function (array $payload): array {
        $data = $payload['props']['alerts']['data'] ?? null;
        if (! is_array($data)) {
            throw new \RuntimeException('Expected props.alerts.data to be an array.');
        }

        return array_map(fn (array $row) => $row['id'] ?? null, $data);
    };

    logInfo('Step 2: Verifying home page inertia payload (status=all)');

    $allPayload = $makeInertiaRequest();
    assertEqual($allPayload['component'] ?? null, 'gta-alerts', 'component = gta-alerts');
    assertTrue(isset($allPayload['props']['alerts']), 'props contains alerts');
    assertTrue(! isset($allPayload['props']['incidents']), 'props does not contain incidents');

    $allIds = $extractAlertIds($allPayload);
    assertEqual(count($allIds), 8, 'alerts count (status=all)');
    assertEqual($allIds[0], 'fire:FIRE-0001', 'first alert id ordering (status=all)');

    $sources = collect($allPayload['props']['alerts']['data'])->pluck('source')->unique()->sort()->values()->all();
    assertEqual($sources, ['fire', 'police'], 'alerts include fire + police sources');

    assertEqual($allPayload['props']['filters']['status'] ?? null, 'all', 'filters.status = all');
    assertEqual($allPayload['props']['latest_feed_updated_at'] ?? null, $expectedLatest, 'latest_feed_updated_at');

    logInfo('Step 3: Verifying status filters');

    $activePayload = $makeInertiaRequest(['status' => 'active']);
    assertEqual($activePayload['props']['filters']['status'] ?? null, 'active', 'filters.status = active');
    $activeRows = $activePayload['props']['alerts']['data'] ?? [];
    assertEqual(count($activeRows), 4, 'alerts count (status=active)');
    assertTrue(collect($activeRows)->every(fn (array $row) => $row['is_active'] === true), 'all active alerts have is_active=true');

    $clearedPayload = $makeInertiaRequest(['status' => 'cleared']);
    assertEqual($clearedPayload['props']['filters']['status'] ?? null, 'cleared', 'filters.status = cleared');
    $clearedRows = $clearedPayload['props']['alerts']['data'] ?? [];
    assertEqual(count($clearedRows), 4, 'alerts count (status=cleared)');
    assertTrue(collect($clearedRows)->every(fn (array $row) => $row['is_active'] === false), 'all cleared alerts have is_active=false');

    if (getenv('RUN_COMMAND_GATES') === '1') {
        logInfo('Step 4: Optional command-based gates (RUN_COMMAND_GATES=1)');

        logInfo('Backend: Pint + test suite');
        runCommand('pint --test', './vendor/bin/pint --test');
        runCommand('php artisan test', 'php artisan test');

        logInfo('Backend: coverage (unified alerts targets)');
        runCommand(
            'pest coverage (app/Services/Alerts)',
            'XDEBUG_MODE=coverage ./vendor/bin/pest --coverage --min=90 --coverage-filter app/Services/Alerts'
        );
        runCommand(
            'pest coverage (GtaAlertsController)',
            'XDEBUG_MODE=coverage ./vendor/bin/pest --coverage --min=90 --coverage-filter app/Http/Controllers/GtaAlertsController.php'
        );
        runCommand(
            'pest coverage (UnifiedAlertResource)',
            'XDEBUG_MODE=coverage ./vendor/bin/pest --coverage --min=90 --coverage-filter app/Http/Resources/UnifiedAlertResource.php'
        );

        logInfo('Frontend: quality checks + coverage smoke check');
        runCommand('corepack enable (best effort)', 'corepack enable || true');
        runCommand('pnpm quality:check', 'pnpm run quality:check');
        runCommand('pnpm coverage', 'pnpm run coverage');

        logInfo('Dependency audits (optional)');
        if (getenv('SKIP_AUDITS') === '1') {
            logInfo('Skipping audits because SKIP_AUDITS=1');
        } else {
            runCommand('composer audit', 'composer audit');
            runCommand('pnpm audit (high+)', 'pnpm audit --audit-level high');
        }
    } else {
        logInfo('Skipping command-based gates (set RUN_COMMAND_GATES=1 to enable).');
    }

    logInfo('=== Manual Test Completed Successfully ===');
} catch (Throwable $e) {
    $exitCode = 1;
    logError('Manual Test Failed', [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
} finally {
    try {
        Carbon::setTestNow();
    } catch (\Throwable) {
    }

    if ($txStarted) {
        try {
            if (DB::connection()->transactionLevel() > 0) {
                DB::rollBack();
                logInfo('Transaction rolled back (Database preserved).');
            }
        } catch (\Throwable) {
        }
    }

    logInfo('=== Test Run Finished ===');

    if ($exitCode === 0) {
        echo "\nResult: PASS\nLogs at: {$logFileRelative}\n";
    } else {
        echo "\nResult: FAIL\nLogs at: {$logFileRelative}\n";
    }

    exit($exitCode);
}
