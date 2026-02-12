<?php

/**
 * Manual Test: Notifications - Phase 4 Notification Center (Inbox)
 * Generated: 2026-02-11
 * Purpose: Verify Phase 4 inbox deliverables and regressions:
 * - Inbox route and controller wiring for list/read/dismiss/clear-all
 * - Ownership boundaries and digest/alert serialization behavior
 * - Pagination query preservation and clear-all timestamp semantics
 * - Frontend inbox contracts (load more, open alert, empty-state handling)
 * - Targeted backend/frontend regression test execution
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

$expectedDatabase = 'gta_alerts_testing';
$connection = config('database.default');
$currentDatabase = config("database.connections.{$connection}.database");
$allowSqliteFallback = getenv('MANUAL_TEST_USE_SQLITE') === '1';

if (! app()->environment('testing')) {
    exit("Error: Manual tests must run with APP_ENV=testing. Destructive test operations are disabled outside the testing environment and cannot be overridden.\n");
}

if (! $allowSqliteFallback && $currentDatabase !== $expectedDatabase) {
    exit("Error: Manual tests must use the '{$expectedDatabase}' database (current: {$currentDatabase}). Destructive test operations are disabled and cannot be overridden.\n");
}

umask(002);

use App\Http\Controllers\Notifications\NotificationInboxController;
use App\Models\NotificationLog;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

$testRunId = 'notifications_phase_4_notification_center_inbox_'.Carbon::now()->format('Y_m_d_His');
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

function assertEqual(mixed $actual, mixed $expected, string $label): void
{
    if ($actual !== $expected) {
        $message = "Assertion failed for {$label}.";
        logError($message, ['expected' => $expected, 'actual' => $actual]);
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

function readFileContents(string $relativePath): string
{
    $absolutePath = base_path($relativePath);
    assertTrue(file_exists($absolutePath), "file exists: {$relativePath}");
    $contents = file_get_contents($absolutePath);
    assertTrue(is_string($contents), "file is readable: {$relativePath}");

    return $contents;
}

/**
 * @param  array<string, mixed>  $query
 */
function makeRequest(User $user, string $method, string $uri, array $query = []): Request
{
    $upperMethod = strtoupper($method);
    $parameters = $query;

    if ($upperMethod === 'GET' && $query !== []) {
        $separator = str_contains($uri, '?') ? '&' : '?';
        $uri .= $separator.http_build_query($query);
        $parameters = [];
    }

    $request = Request::create(
        uri: $uri,
        method: $upperMethod,
        parameters: $parameters,
        server: ['HTTP_ACCEPT' => 'application/json']
    );
    $request->setUserResolver(static fn (): User => $user);

    return $request;
}

/**
 * @return array<string, mixed>
 */
function decodeJsonResponse(\Illuminate\Http\JsonResponse $response): array
{
    $decoded = $response->getData(true);
    assertTrue(is_array($decoded), 'response payload decodes to array');

    return $decoded;
}

function withBoundRequest(Request $request, callable $callback): mixed
{
    $container = app();
    $previousRequest = $container->bound('request') ? $container->make('request') : null;
    $userResolver = $request->getUserResolver();

    $container->instance('request', $request);
    $request->setUserResolver($userResolver);

    try {
        return $callback();
    } finally {
        if ($previousRequest !== null) {
            $container->instance('request', $previousRequest);
        }
    }
}

function configureSqliteFallback(string $databasePath): void
{
    $databaseDir = dirname($databasePath);

    if (! is_dir($databaseDir) && ! mkdir($databaseDir, 0775, true) && ! is_dir($databaseDir)) {
        throw new RuntimeException("Failed to create sqlite directory: {$databaseDir}");
    }

    if (! file_exists($databasePath) && @touch($databasePath) === false) {
        throw new RuntimeException("Failed to create sqlite database file: {$databasePath}");
    }

    config([
        'database.default' => 'sqlite',
        'database.connections.sqlite.database' => $databasePath,
        'database.connections.sqlite.foreign_key_constraints' => true,
    ]);

    DB::purge('sqlite');
    DB::reconnect('sqlite');
}

$exitCode = 0;
$txStarted = false;

try {
    $sqliteFallbackPath = storage_path("app/private/manual_tests/{$testRunId}.sqlite");
    $sqliteSuitePath = storage_path("app/private/manual_tests/{$testRunId}_suite.sqlite");

    if ($allowSqliteFallback) {
        configureSqliteFallback($sqliteFallbackPath);

        $connection = config('database.default');
        $currentDatabase = config("database.connections.{$connection}.database");

        logInfo('SQLite fallback mode enabled for manual verification', [
            'database' => $currentDatabase,
        ]);
    } else {
        try {
            DB::connection()->getPdo();
        } catch (Throwable $e) {
            throw new RuntimeException(
                "Database connection failed. If you're using Sail, run: ./scripts/init-testing-environment.sh",
                previous: $e
            );
        }
    }

    logInfo('Boot context', [
        'app_env' => app()->environment(),
        'db_connection' => $connection,
        'db_database' => $currentDatabase,
    ]);

    if ($allowSqliteFallback) {
        Artisan::call('migrate:fresh', [
            '--database' => 'sqlite',
            '--force' => true,
        ]);
        logInfo('SQLite fallback migrations refreshed', ['output' => trim(Artisan::output())]);
    } elseif (! Schema::hasTable('notification_logs')) {
        logInfo('notification_logs table missing; running migrations for testing database');
        Artisan::call('migrate', ['--force' => true]);
        logInfo('Migration output', ['output' => trim(Artisan::output())]);
    }

    DB::beginTransaction();
    $txStarted = true;

    logInfo('=== Starting Manual Test: Notifications Phase 4 Notification Center (Inbox) ===');

    logInfo('Phase 1: Verify route + artifact wiring and post-review code contracts');

    foreach ([
        'app/Http/Controllers/Notifications/NotificationInboxController.php',
        'tests/Feature/Notifications/NotificationInboxControllerTest.php',
        'resources/js/features/gta-alerts/components/NotificationInboxView.tsx',
        'resources/js/features/gta-alerts/components/NotificationInboxView.test.tsx',
        'resources/js/features/gta-alerts/services/NotificationInboxService.ts',
        'resources/js/features/gta-alerts/App.tsx',
        'resources/js/features/gta-alerts/App.test.tsx',
    ] as $file) {
        assertTrue(file_exists(base_path($file)), "artifact exists: {$file}");
    }

    $indexRoute = app('router')->getRoutes()->getByName('notifications.inbox.index');
    assertTrue($indexRoute !== null, 'notifications.inbox.index route is registered');
    if ($indexRoute !== null) {
        assertEqual($indexRoute->uri(), 'notifications/inbox', 'notifications.inbox.index URI');
        assertTrue(in_array('GET', $indexRoute->methods(), true), 'notifications.inbox.index supports GET');
        assertTrue(in_array('auth', $indexRoute->gatherMiddleware(), true), 'notifications.inbox.index is auth-protected');
    }

    $readRoute = app('router')->getRoutes()->getByName('notifications.inbox.read');
    assertTrue($readRoute !== null, 'notifications.inbox.read route is registered');
    if ($readRoute !== null) {
        assertEqual($readRoute->uri(), 'notifications/inbox/{notificationLog}/read', 'notifications.inbox.read URI');
        assertTrue(in_array('PATCH', $readRoute->methods(), true), 'notifications.inbox.read supports PATCH');
        assertTrue(in_array('auth', $readRoute->gatherMiddleware(), true), 'notifications.inbox.read is auth-protected');
    }

    $dismissRoute = app('router')->getRoutes()->getByName('notifications.inbox.dismiss');
    assertTrue($dismissRoute !== null, 'notifications.inbox.dismiss route is registered');
    if ($dismissRoute !== null) {
        assertEqual($dismissRoute->uri(), 'notifications/inbox/{notificationLog}/dismiss', 'notifications.inbox.dismiss URI');
        assertTrue(in_array('PATCH', $dismissRoute->methods(), true), 'notifications.inbox.dismiss supports PATCH');
        assertTrue(in_array('auth', $dismissRoute->gatherMiddleware(), true), 'notifications.inbox.dismiss is auth-protected');
    }

    $clearRoute = app('router')->getRoutes()->getByName('notifications.inbox.clear');
    assertTrue($clearRoute !== null, 'notifications.inbox.clear route is registered');
    if ($clearRoute !== null) {
        assertEqual($clearRoute->uri(), 'notifications/inbox', 'notifications.inbox.clear URI');
        assertTrue(in_array('DELETE', $clearRoute->methods(), true), 'notifications.inbox.clear supports DELETE');
        assertTrue(in_array('auth', $clearRoute->gatherMiddleware(), true), 'notifications.inbox.clear is auth-protected');
    }

    $controllerContents = readFileContents('app/Http/Controllers/Notifications/NotificationInboxController.php');
    assertContainsText('->withQueryString();', $controllerContents, 'controller preserves query strings in paginator links');
    assertContainsText('$quotedNow', $controllerContents, 'controller snapshots application time for clear-all update');
    assertContainsText('COALESCE(read_at,', $controllerContents, 'controller performs atomic clear-all read timestamp update');
    assertContainsText("'dismissed_count' => \$dismissedCount", $controllerContents, 'controller reports SQL-updated dismissal count');

    $inboxViewContents = readFileContents('resources/js/features/gta-alerts/components/NotificationInboxView.tsx');
    assertContainsText('const [nextPageUrl, setNextPageUrl] = useState<string | null>(null);', $inboxViewContents, 'inbox view tracks next-page pagination link');
    assertContainsText('const [hasLoadedInbox, setHasLoadedInbox] = useState(false);', $inboxViewContents, 'inbox view gates empty-state rendering after successful load');
    assertContainsText('const inboxDateFormatter = new Intl.DateTimeFormat(', $inboxViewContents, 'inbox view reuses a module-level date formatter');
    assertContainsText('onOpenAlert?: (alertId: string) => void;', $inboxViewContents, 'inbox view accepts alert-open callback');
    assertContainsText("'Load older notifications'", $inboxViewContents, 'inbox view includes load-more control');

    $inboxServiceContents = readFileContents('resources/js/features/gta-alerts/services/NotificationInboxService.ts');
    assertContainsText('pageUrl?: string;', $inboxServiceContents, 'inbox service supports raw pagination URLs');
    assertContainsText('const normalizePageUrl = (url: string): string => {', $inboxServiceContents, 'inbox service normalizes pagination URL origin');
    assertContainsText('return `${parsed.pathname}${parsed.search}`;', $inboxServiceContents, 'inbox service strips origin for same-origin fetch');

    $appContents = readFileContents('resources/js/features/gta-alerts/App.tsx');
    assertContainsText('<NotificationInboxView', $appContents, 'app mounts notification inbox view');
    assertContainsText('onOpenAlert={setActiveAlertId}', $appContents, 'app routes inbox alert selection to details view');

    logInfo('Phase 2: Verify backend inbox behavior (list/read/dismiss/clear-all)');

    $controller = app(NotificationInboxController::class);
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $alertLog = NotificationLog::factory()->create([
        'user_id' => $user->id,
        'alert_id' => 'police:manual-1',
        'delivery_method' => 'in_app',
        'status' => 'delivered',
        'sent_at' => CarbonImmutable::parse('2026-02-11T14:00:00Z'),
        'read_at' => null,
        'dismissed_at' => null,
        'metadata' => [
            'source' => 'police',
            'summary' => 'Manual inbox verification alert',
        ],
    ]);

    $digestLog = NotificationLog::factory()->create([
        'user_id' => $user->id,
        'alert_id' => 'digest:2026-02-11',
        'delivery_method' => 'in_app_digest',
        'status' => 'sent',
        'sent_at' => CarbonImmutable::parse('2026-02-11T15:00:00Z'),
        'read_at' => null,
        'dismissed_at' => null,
        'metadata' => [
            'type' => 'daily_digest',
            'digest_date' => '2026-02-11',
            'total_notifications' => 2,
        ],
    ]);

    $dismissedLog = NotificationLog::factory()->create([
        'user_id' => $user->id,
        'delivery_method' => 'in_app',
        'status' => 'dismissed',
        'sent_at' => CarbonImmutable::parse('2026-02-11T16:00:00Z'),
        'read_at' => CarbonImmutable::parse('2026-02-11T16:00:30Z'),
        'dismissed_at' => CarbonImmutable::parse('2026-02-11T16:00:45Z'),
    ]);

    $otherUserLog = NotificationLog::factory()->create([
        'user_id' => $otherUser->id,
        'delivery_method' => 'in_app',
        'status' => 'delivered',
        'sent_at' => CarbonImmutable::parse('2026-02-11T16:05:00Z'),
        'read_at' => null,
        'dismissed_at' => null,
    ]);

    $defaultListRequest = makeRequest($user, 'GET', '/notifications/inbox');
    $defaultListPayload = decodeJsonResponse(
        withBoundRequest($defaultListRequest, fn () => $controller->index($defaultListRequest))
    );

    assertEqual($defaultListPayload['meta']['unread_count'] ?? null, 2, 'default inbox meta unread_count includes unread alert + digest');
    assertEqual(count($defaultListPayload['data'] ?? []), 2, 'default inbox excludes dismissed entries');

    $defaultItemsById = collect($defaultListPayload['data'] ?? [])->keyBy('id');
    assertEqual($defaultItemsById->get($digestLog->id)['type'] ?? null, 'digest', 'digest delivery is serialized as digest type');
    assertEqual($defaultItemsById->get($alertLog->id)['type'] ?? null, 'alert', 'standard in-app delivery is serialized as alert type');
    assertTrue($defaultItemsById->has($dismissedLog->id) === false, 'dismissed items are hidden by default');

    $filteredListRequest = makeRequest($user, 'GET', '/notifications/inbox', [
        'include_dismissed' => 1,
        'per_page' => 1,
        'page' => 1,
    ]);
    $filteredListPayload = decodeJsonResponse(
        withBoundRequest($filteredListRequest, fn () => $controller->index($filteredListRequest))
    );

    assertEqual($filteredListPayload['meta']['per_page'] ?? null, 1, 'inbox list honors per_page override');
    assertEqual($filteredListPayload['meta']['total'] ?? null, 3, 'inbox list include_dismissed contains all user rows');
    assertTrue(
        str_contains((string) ($filteredListPayload['links']['next'] ?? ''), 'include_dismissed=1'),
        'pagination next link preserves include_dismissed filter'
    );
    assertTrue(
        str_contains((string) ($filteredListPayload['links']['next'] ?? ''), 'per_page=1'),
        'pagination next link preserves custom per_page value'
    );

    $markReadPayload = decodeJsonResponse(
        $controller->markRead(makeRequest($user, 'PATCH', "/notifications/inbox/{$alertLog->id}/read"), $alertLog->id)
    );
    assertEqual($markReadPayload['data']['status'] ?? null, 'read', 'mark read transitions status to read');
    $alertLog->refresh();
    assertTrue($alertLog->read_at !== null, 'mark read sets read_at timestamp');

    $ownershipReadDenied = false;
    try {
        $controller->markRead(
            makeRequest($user, 'PATCH', "/notifications/inbox/{$otherUserLog->id}/read"),
            $otherUserLog->id
        );
    } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
        $ownershipReadDenied = true;
    }
    assertTrue($ownershipReadDenied, 'mark read enforces ownership boundaries');

    $dismissPayload = decodeJsonResponse(
        $controller->dismiss(
            makeRequest($user, 'PATCH', "/notifications/inbox/{$digestLog->id}/dismiss"),
            $digestLog->id
        )
    );
    assertEqual($dismissPayload['data']['status'] ?? null, 'dismissed', 'dismiss transitions status to dismissed');
    $digestLog->refresh();
    assertTrue($digestLog->dismissed_at !== null, 'dismiss sets dismissed_at timestamp');
    assertTrue($digestLog->read_at !== null, 'dismiss sets read_at timestamp when previously null');

    $ownershipDismissDenied = false;
    try {
        $controller->dismiss(
            makeRequest($user, 'PATCH', "/notifications/inbox/{$otherUserLog->id}/dismiss"),
            $otherUserLog->id
        );
    } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
        $ownershipDismissDenied = true;
    }
    assertTrue($ownershipDismissDenied, 'dismiss enforces ownership boundaries');

    $existingReadAt = CarbonImmutable::parse('2026-02-11T17:00:00Z');
    $clearOne = NotificationLog::factory()->create([
        'user_id' => $user->id,
        'status' => 'delivered',
        'read_at' => null,
        'dismissed_at' => null,
        'sent_at' => CarbonImmutable::parse('2026-02-11T17:10:00Z'),
    ]);
    $clearTwo = NotificationLog::factory()->create([
        'user_id' => $user->id,
        'status' => 'read',
        'read_at' => $existingReadAt,
        'dismissed_at' => null,
        'sent_at' => CarbonImmutable::parse('2026-02-11T17:20:00Z'),
    ]);
    $alreadyDismissed = NotificationLog::factory()->create([
        'user_id' => $user->id,
        'status' => 'dismissed',
        'read_at' => CarbonImmutable::parse('2026-02-11T17:25:00Z'),
        'dismissed_at' => CarbonImmutable::parse('2026-02-11T17:26:00Z'),
        'sent_at' => CarbonImmutable::parse('2026-02-11T17:25:00Z'),
    ]);
    $otherUserBeforeClear = NotificationLog::factory()->create([
        'user_id' => $otherUser->id,
        'status' => 'delivered',
        'read_at' => null,
        'dismissed_at' => null,
        'sent_at' => CarbonImmutable::parse('2026-02-11T17:27:00Z'),
    ]);

    $frozenNow = CarbonImmutable::parse('2026-02-11T18:00:30Z');
    CarbonImmutable::setTestNow($frozenNow);

    try {
        $clearPayload = decodeJsonResponse(
            $controller->clearAll(makeRequest($user, 'DELETE', '/notifications/inbox'))
        );
    } finally {
        CarbonImmutable::setTestNow();
    }

    assertEqual($clearPayload['meta']['dismissed_count'] ?? null, 3, 'clear-all dismisses only undismissed rows');
    assertEqual($clearPayload['meta']['unread_count'] ?? null, 0, 'clear-all unread count excludes dismissed rows');

    $clearOne->refresh();
    $clearTwo->refresh();
    $alreadyDismissed->refresh();
    $otherUserBeforeClear->refresh();

    assertEqual($clearOne->read_at?->toDateTimeString(), $frozenNow->toDateTimeString(), 'clear-all sets read_at using application clock when null');
    assertEqual($clearOne->dismissed_at?->toDateTimeString(), $frozenNow->toDateTimeString(), 'clear-all sets dismissed_at using application clock');
    assertEqual($clearOne->status, 'dismissed', 'clear-all transitions delivered rows to dismissed status');
    assertEqual($clearTwo->read_at?->toISOString(), $existingReadAt->toISOString(), 'clear-all preserves existing read_at values');
    assertEqual($clearTwo->status, 'dismissed', 'clear-all transitions previously read rows to dismissed status');
    assertEqual($alreadyDismissed->dismissed_at?->toISOString(), CarbonImmutable::parse('2026-02-11T17:26:00Z')->toISOString(), 'clear-all leaves already dismissed rows unchanged');
    assertEqual($otherUserBeforeClear->status, 'delivered', 'clear-all does not affect other users');

    logInfo('Phase 3: Execute targeted backend and frontend regression suites');
    if ($txStarted && DB::transactionLevel() > 0) {
        DB::rollBack();
        $txStarted = false;
        logInfo('Released manual transaction before spawning regression suites to avoid cross-process DB lock contention.');
    }

    $backendCommand = 'php artisan test tests/Feature/Notifications/NotificationInboxControllerTest.php';
    if ($allowSqliteFallback) {
        if (! file_exists($sqliteSuitePath) && @touch($sqliteSuitePath) === false) {
            throw new RuntimeException("Failed to create sqlite suite database file: {$sqliteSuitePath}");
        }

        $backendCommand = 'DB_CONNECTION=sqlite DB_DATABASE='.escapeshellarg($sqliteSuitePath).' '.$backendCommand;
    }

    $backendTests = runCommand(
        $backendCommand,
        'Notification inbox feature test suite'
    );
    assertTrue(
        $backendTests['exit_code'] === 0,
        'notification inbox feature tests pass',
        ['exit_code' => $backendTests['exit_code']]
    );

    $frontendTests = runCommand(
        'CI=true LARAVEL_BYPASS_ENV_CHECK=1 pnpm exec vitest run resources/js/features/gta-alerts/components/NotificationInboxView.test.tsx resources/js/features/gta-alerts/App.test.tsx',
        'Notification inbox frontend vitest suite'
    );
    assertTrue(
        $frontendTests['exit_code'] === 0,
        'notification inbox frontend vitest tests pass',
        ['exit_code' => $frontendTests['exit_code']]
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
    if ($txStarted && DB::transactionLevel() > 0) {
        DB::rollBack();
        logInfo('Rolled back transaction; no persistent data changes were kept.');
    }

    logInfo('Manual test log file', ['path' => $logFileRelative]);
}

exit($exitCode);
