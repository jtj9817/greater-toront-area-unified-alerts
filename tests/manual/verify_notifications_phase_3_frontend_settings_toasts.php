<?php

/**
 * Manual Test: Notifications - Phase 3 Frontend Settings & Toasts
 * Generated: 2026-02-10
 * Purpose: Verify Phase 3 frontend deliverables for notification settings and
 * realtime toast UX:
 * - Settings UI controls and route option sourcing contract
 * - Notification toast payload handling, lifecycle cleanup, and channel wiring
 * - Echo bootstrap wiring for realtime delivery
 * - Notification preference route availability for frontend API calls
 * - Targeted frontend regression tests for settings/toast behavior
 */

require __DIR__.'/../../vendor/autoload.php';

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

$app = require_once __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

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

$testRunId = 'notifications_phase_3_frontend_settings_toasts_'.Carbon::now()->format('Y_m_d_His');
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

function assertContainsText(string $needle, string $haystack, string $label): void
{
    assertTrue(str_contains($haystack, $needle), $label, ['needle' => $needle]);
}

/**
 * @return array{exit_code: int|null, output: string}
 */
function runCommand(string $command, string $label): array
{
    logInfo("Running command: {$label}", ['command' => $command]);

    $process = new Process(['bash', '-lc', $command], base_path());
    $process->setTimeout(null);

    $output = '';
    $process->run(function (string $type, string $buffer) use (&$output): void {
        $output .= $buffer;
        echo $buffer;
    });

    $exitCode = $process->getExitCode();

    Log::channel('manual_test')->info("Command output: {$label}", [
        'exit_code' => $exitCode,
        'output' => $output,
    ]);

    return [
        'exit_code' => $exitCode,
        'output' => $output,
    ];
}

/**
 * @return string
 */
function readFileContents(string $relativePath): string
{
    $absolutePath = base_path($relativePath);
    assertTrue(file_exists($absolutePath), "file exists: {$relativePath}");
    $contents = file_get_contents($absolutePath);
    assertTrue(is_string($contents), "file is readable: {$relativePath}");

    return $contents;
}

$exitCode = 0;

try {
    logInfo('Boot context', [
        'app_env' => app()->environment(),
    ]);
    logInfo('=== Starting Manual Test: Notifications Phase 3 Frontend Settings & Toasts ===');

    logInfo('Phase 1: Verify required frontend artifacts exist');

    foreach ([
        'resources/js/features/gta-alerts/App.tsx',
        'resources/js/features/gta-alerts/components/SettingsView.tsx',
        'resources/js/features/gta-alerts/components/NotificationToastLayer.tsx',
        'resources/js/features/gta-alerts/components/SettingsView.test.tsx',
        'resources/js/features/gta-alerts/components/NotificationToastLayer.test.tsx',
        'resources/js/features/gta-alerts/services/NotificationPreferenceService.ts',
        'resources/js/features/gta-alerts/constants/routes.ts',
        'resources/js/lib/echo.ts',
        'resources/js/app.tsx',
    ] as $file) {
        assertTrue(file_exists(base_path($file)), "artifact exists: {$file}");
    }

    logInfo('Phase 2: Verify settings and toast contract wiring');

    $settingsViewContents = readFileContents('resources/js/features/gta-alerts/components/SettingsView.tsx');
    assertContainsText("import { KNOWN_TRANSIT_ROUTES } from '../constants/routes';", $settingsViewContents, 'settings view imports known routes registry');
    assertContainsText('...availableRoutes,', $settingsViewContents, 'settings route options include backend-provided routes');
    assertContainsText('...preference.subscribed_routes,', $settingsViewContents, 'settings route options include persisted subscriptions');
    assertContainsText('...KNOWN_TRANSIT_ROUTES,', $settingsViewContents, 'settings route options include known route registry fallback');
    assertContainsText('Severity threshold', $settingsViewContents, 'settings includes severity threshold control');
    assertContainsText('Geofence Zones', $settingsViewContents, 'settings includes geofence section');
    assertContainsText('Enable real-time push toasts', $settingsViewContents, 'settings includes realtime push toggle');
    assertContainsText('Enable daily digest mode', $settingsViewContents, 'settings includes digest toggle');
    assertContainsText('Save notification settings', $settingsViewContents, 'settings includes save action');

    $toastLayerContents = readFileContents('resources/js/features/gta-alerts/components/NotificationToastLayer.tsx');
    assertContainsText("const TOAST_EVENT = '.alert.notification.sent';", $toastLayerContents, 'toast event alias matches broadcast name');
    assertContainsText('NotificationToastSchema.safeParse(payload)', $toastLayerContents, 'toast payload is schema validated');
    assertContainsText('const timersRef = useRef<Record<string, number>>({});', $toastLayerContents, 'toast timers tracked by toast id record');
    assertContainsText('delete timersRef.current[toastId];', $toastLayerContents, 'toast timer cleanup removes timer id entry');
    assertContainsText('channel.stopListening(TOAST_EVENT);', $toastLayerContents, 'toast unsubscribes event listener on cleanup');
    assertContainsText('window.Echo?.leave(channelName);', $toastLayerContents, 'toast leaves private channel on cleanup');

    $appContents = readFileContents('resources/js/features/gta-alerts/App.tsx');
    assertContainsText('availableRoutes={routeOptions}', $appContents, 'app forwards route options into settings view');
    assertContainsText('<NotificationToastLayer authUserId={authUserId} />', $appContents, 'app mounts persistent notification toast layer');

    $echoContents = readFileContents('resources/js/lib/echo.ts');
    assertContainsText("authEndpoint: '/broadcasting/auth'", $echoContents, 'echo uses broadcasting auth endpoint');
    assertContainsText("window.Echo = new Echo({", $echoContents, 'echo client is initialized when key exists');
    assertContainsText("enabledTransports: ['ws', 'wss']", $echoContents, 'echo websocket transports are configured');

    $bootstrapContents = readFileContents('resources/js/app.tsx');
    assertContainsText("import './lib/echo';", $bootstrapContents, 'frontend bootstrap imports echo initialization');

    logInfo('Phase 3: Verify notification preference route exposure for frontend APIs');

    $showRoute = app('router')->getRoutes()->getByName('notifications.show');
    assertTrue($showRoute !== null, 'notifications.show route is registered');
    if ($showRoute !== null) {
        assertTrue($showRoute->uri() === 'settings/notifications', 'notifications.show URI');
        assertTrue(in_array('GET', $showRoute->methods(), true), 'notifications.show supports GET');
        assertTrue(in_array('auth', $showRoute->gatherMiddleware(), true), 'notifications.show is auth-protected');
    }

    $updateRoute = app('router')->getRoutes()->getByName('notifications.update');
    assertTrue($updateRoute !== null, 'notifications.update route is registered');
    if ($updateRoute !== null) {
        assertTrue($updateRoute->uri() === 'settings/notifications', 'notifications.update URI');
        assertTrue(in_array('PATCH', $updateRoute->methods(), true), 'notifications.update supports PATCH');
        assertTrue(in_array('auth', $updateRoute->gatherMiddleware(), true), 'notifications.update is auth-protected');
    }

    logInfo('Phase 4: Execute targeted frontend regression tests');

    $vitestResult = runCommand(
        'CI=true LARAVEL_BYPASS_ENV_CHECK=1 pnpm exec vitest run resources/js/features/gta-alerts/components/SettingsView.test.tsx resources/js/features/gta-alerts/components/NotificationToastLayer.test.tsx resources/js/features/gta-alerts/App.test.tsx',
        'Phase 3 frontend vitest suite'
    );
    assertTrue(
        $vitestResult['exit_code'] === 0,
        'phase 3 targeted frontend tests pass',
        ['exit_code' => $vitestResult['exit_code']]
    );

    logInfo('=== Manual Test Completed Successfully ===');
} catch (Throwable $e) {
    $exitCode = 1;
    logError('Manual Test Failed', [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
} finally {
    logInfo('Manual test log file', ['path' => $logFileRelative]);
}

exit($exitCode);
