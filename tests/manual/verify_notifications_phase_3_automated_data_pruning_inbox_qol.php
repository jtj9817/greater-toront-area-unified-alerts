<?php

/**
 * Manual Test: Notifications - Phase 3 Automated Data Pruning & Inbox QoL
 * Generated: 2026-02-13
 * Purpose: Verify Phase 3 deliverables:
 * - notifications:prune command behavior and 30-day retention boundary.
 * - Daily scheduler registration for notifications:prune.
 * - Inbox bulk APIs (mark-all-read and clear-all) with ownership boundaries.
 * - Frontend inbox header action contracts and race-guard protections.
 * - Targeted backend/frontend regression suite execution.
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

if (! app()->environment('testing')) {
    exit("Error: Manual tests must run with APP_ENV=testing. Destructive test operations are disabled outside the testing environment and cannot be overridden.\n");
}

$expectedDatabase = 'gta_alerts_testing';
$allowSqliteFallback = getenv('MANUAL_TEST_USE_SQLITE') === '1';
$connection = config('database.default');
$currentDatabase = config("database.connections.{$connection}.database");

if (! $allowSqliteFallback && $currentDatabase !== $expectedDatabase) {
    exit("Error: Manual tests must use the '{$expectedDatabase}' database (current: {$currentDatabase}). Destructive test operations are disabled and cannot be overridden.\n");
}

umask(002);

use App\Console\Commands\PruneNotificationsCommand;
use App\Http\Controllers\Notifications\NotificationInboxController;
use App\Models\NotificationLog;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

$testRunId = 'notifications_phase_3_automated_data_pruning_inbox_qol_'.Carbon::now()->format('Y_m_d_His');
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

function readFileContents(string $relativePath): string
{
    $absolutePath = base_path($relativePath);
    assertTrue(file_exists($absolutePath), "file exists: {$relativePath}");
    $contents = file_get_contents($absolutePath);
    assertTrue(is_string($contents), "file is readable: {$relativePath}");

    return $contents;
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
function decodeJsonResponse(JsonResponse $response): array
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

function ensureSqliteDatabaseFile(string $databasePath): void
{
    $databaseDir = dirname($databasePath);

    if (! is_dir($databaseDir) && ! mkdir($databaseDir, 0775, true) && ! is_dir($databaseDir)) {
        throw new RuntimeException("Failed to create sqlite directory: {$databaseDir}");
    }

    if (! file_exists($databasePath) && @touch($databasePath) === false) {
        throw new RuntimeException("Failed to create sqlite database file: {$databasePath}");
    }
}

function configureSqliteFallback(string $databasePath): void
{
    ensureSqliteDatabaseFile($databasePath);

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
$usingSqliteFallback = false;
$sqliteFallbackPath = storage_path("app/private/manual_tests/{$testRunId}.sqlite");
$sqliteSuitePath = storage_path("app/private/manual_tests/{$testRunId}_suite.sqlite");

if ($allowSqliteFallback) {
    configureSqliteFallback($sqliteFallbackPath);
    $usingSqliteFallback = true;
}

try {
    try {
        DB::connection()->getPdo();
    } catch (Throwable $e) {
        if (! $allowSqliteFallback || $usingSqliteFallback) {
            throw new RuntimeException(
                "Database connection failed. If you're using Sail, run: ./scripts/init-testing-environment.sh or set MANUAL_TEST_USE_SQLITE=1 to allow sqlite fallback.",
                previous: $e
            );
        }

        logInfo('Primary DB connection failed; enabling sqlite fallback', ['reason' => $e->getMessage()]);
        configureSqliteFallback($sqliteFallbackPath);
        $usingSqliteFallback = true;
    }

    $activeConnection = config('database.default');
    $activeDatabase = config("database.connections.{$activeConnection}.database");

    logInfo('=== Starting Manual Test: Notifications Phase 3 Automated Data Pruning & Inbox QoL ===');
    logInfo('Protocol Step 1/5: Verification started', [
        'app_env' => app()->environment(),
        'db_connection' => $activeConnection,
        'db_database' => $activeDatabase,
        'sqlite_fallback' => $usingSqliteFallback,
    ]);

    if (! Schema::hasTable('notification_logs')) {
        logInfo('notification_logs table missing; running migrations');
        Artisan::call('migrate', ['--force' => true]);
        logInfo('Migration output', ['output' => trim(Artisan::output())]);
    }

    DB::beginTransaction();
    $txStarted = true;

    logInfo('Protocol Step 2/5: Verifying changed modules have corresponding tests');

    $featureCommitLookup = runCommand(
        'git log --format=%H --grep="feat(notifications): add prune and bulk inbox ops" -n 1',
        'Locate phase 3 feature implementation commit'
    );
    assertTrue($featureCommitLookup['exit_code'] === 0, 'feature commit lookup command succeeds');
    $featureCommit = trim($featureCommitLookup['output']);
    assertTrue($featureCommit !== '', 'feature implementation commit hash located');

    $featureCommitDiff = runCommand(
        "git show --name-only --pretty='' {$featureCommit}",
        'List changed files for phase 3 implementation commit'
    );
    assertTrue($featureCommitDiff['exit_code'] === 0, 'feature commit diff command succeeds');

    $changedFiles = array_values(array_filter(array_map(
        static fn (string $line): string => trim($line),
        explode("\n", $featureCommitDiff['output'])
    )));
    assertTrue($changedFiles !== [], 'feature implementation commit has changed files');

    logInfo('Feature commit changed files summary', [
        'commit' => $featureCommit,
        'count' => count($changedFiles),
    ]);

    $expectedTestCoverageMap = [
        'app/Console/Commands/PruneNotificationsCommand.php' => 'tests/Feature/Commands/PruneNotificationsCommandTest.php',
        'app/Http/Controllers/Notifications/NotificationInboxController.php' => 'tests/Feature/Notifications/NotificationInboxControllerTest.php',
        'resources/js/features/gta-alerts/components/NotificationInboxView.tsx' => 'resources/js/features/gta-alerts/components/NotificationInboxView.test.tsx',
        'routes/console.php' => 'tests/Feature/Console/NotificationPruningScheduleTest.php',
    ];

    foreach ($expectedTestCoverageMap as $sourcePath => $testPath) {
        assertTrue(file_exists(base_path($sourcePath)), "feature file exists: {$sourcePath}");
        assertTrue(file_exists(base_path($testPath)), "coverage test exists: {$testPath}");
        assertTrue(in_array($sourcePath, $changedFiles, true), "feature file is part of tracked implementation diff: {$sourcePath}");
    }

    logInfo('Phase A: Verify command, scheduler, route, and frontend wiring artifacts');

    foreach ([
        'app/Console/Commands/PruneNotificationsCommand.php',
        'app/Http/Controllers/Notifications/NotificationInboxController.php',
        'resources/js/features/gta-alerts/components/NotificationInboxView.tsx',
        'resources/js/features/gta-alerts/components/NotificationInboxView.test.tsx',
        'resources/js/features/gta-alerts/services/NotificationInboxService.ts',
        'tests/Feature/Commands/PruneNotificationsCommandTest.php',
        'tests/Feature/Console/NotificationPruningScheduleTest.php',
        'tests/Feature/Notifications/NotificationInboxControllerTest.php',
    ] as $file) {
        assertTrue(file_exists(base_path($file)), "artifact exists: {$file}");
    }

    $artisanCommands = Artisan::all();
    assertTrue(isset($artisanCommands['notifications:prune']), 'notifications:prune artisan command is registered');
    assertTrue($artisanCommands['notifications:prune'] instanceof PruneNotificationsCommand, 'notifications:prune resolves to PruneNotificationsCommand');

    $pruneCommandContents = readFileContents('app/Console/Commands/PruneNotificationsCommand.php');
    assertContainsText("'notifications:prune'", $pruneCommandContents, 'prune command uses expected signature');
    assertContainsText('now()->subDays(30)', $pruneCommandContents, 'prune command computes 30-day cutoff');
    assertContainsText("where('sent_at', '<', \$cutoff)", $pruneCommandContents, 'prune command only deletes stale sent_at rows');

    $consoleRoutesContents = readFileContents('routes/console.php');
    assertContainsText("Schedule::command('notifications:prune')->daily()->withoutOverlapping();", $consoleRoutesContents, 'scheduler registers prune command daily without overlap');

    $schedule = app(Schedule::class);
    $pruneEvent = collect($schedule->events())->first(static function ($event): bool {
        return is_string($event->command) && str_contains($event->command, 'notifications:prune');
    });
    assertTrue($pruneEvent !== null, 'notifications:prune schedule event exists');

    if ($pruneEvent !== null) {
        assertEqual($pruneEvent->expression, '0 0 * * *', 'notifications:prune schedule uses daily cron expression');
    }

    $readAllRoute = app('router')->getRoutes()->getByName('notifications.inbox.read-all');
    assertTrue($readAllRoute !== null, 'notifications.inbox.read-all route is registered');
    if ($readAllRoute !== null) {
        assertEqual($readAllRoute->uri(), 'notifications/inbox/read-all', 'notifications.inbox.read-all URI');
        assertTrue(in_array('PATCH', $readAllRoute->methods(), true), 'notifications.inbox.read-all supports PATCH');
        assertTrue(in_array('auth', $readAllRoute->gatherMiddleware(), true), 'notifications.inbox.read-all is auth-protected');
    }

    $clearRoute = app('router')->getRoutes()->getByName('notifications.inbox.clear');
    assertTrue($clearRoute !== null, 'notifications.inbox.clear route is registered');
    if ($clearRoute !== null) {
        assertEqual($clearRoute->uri(), 'notifications/inbox', 'notifications.inbox.clear URI');
        assertTrue(in_array('DELETE', $clearRoute->methods(), true), 'notifications.inbox.clear supports DELETE');
        assertTrue(in_array('auth', $clearRoute->gatherMiddleware(), true), 'notifications.inbox.clear is auth-protected');
    }

    $controllerContents = readFileContents('app/Http/Controllers/Notifications/NotificationInboxController.php');
    assertContainsText('public function markAllRead', $controllerContents, 'controller exposes markAllRead endpoint handler');
    assertContainsText('whereNull(\'read_at\')', $controllerContents, 'markAllRead scopes to unread rows');
    assertContainsText('whereNull(\'dismissed_at\')', $controllerContents, 'markAllRead excludes dismissed rows');
    assertContainsText('public function clearAll', $controllerContents, 'controller exposes clearAll endpoint handler');
    assertContainsText('COALESCE(read_at,', $controllerContents, 'clearAll uses SQL coalesce to preserve existing read_at values');

    $inboxViewContents = readFileContents('resources/js/features/gta-alerts/components/NotificationInboxView.tsx');
    assertContainsText('const hasInFlightItemOrPagingAction =', $inboxViewContents, 'inbox view tracks combined per-item/paging busy state');
    assertContainsText('if (isClearing || isMarkingAllRead || hasInFlightItemOrPagingAction) {', $inboxViewContents, 'markAllRead guard blocks races with other actions');
    assertContainsText('if (isMarkingAllRead || hasInFlightItemOrPagingAction) {', $inboxViewContents, 'clearAll guard blocks races with item/paging actions');
    assertContainsText('activeItemId !== null', $inboxViewContents, 'loadMore guard blocks paging during per-item in-flight action');
    assertContainsText('{isMarkingAllRead ? \'Marking...\' : \'Mark all read\'}', $inboxViewContents, 'inbox header shows mark-all progress state');
    assertContainsText('{isClearing ? \'Clearing...\' : \'Clear all\'}', $inboxViewContents, 'inbox header shows clear-all progress state');

    $inboxServiceContents = readFileContents('resources/js/features/gta-alerts/services/NotificationInboxService.ts');
    assertContainsText('export const markAllNotificationsAsRead = async (): Promise<MarkAllReadResult>', $inboxServiceContents, 'inbox service exposes markAllNotificationsAsRead helper');
    assertContainsText('export const clearNotificationInbox = async (): Promise<ClearInboxResult>', $inboxServiceContents, 'inbox service exposes clearNotificationInbox helper');

    $inboxViewTestContents = readFileContents('resources/js/features/gta-alerts/components/NotificationInboxView.test.tsx');
    assertContainsText('marks all notifications as read from the inbox header action', $inboxViewTestContents, 'frontend test covers mark-all header behavior');
    assertContainsText('clears all notifications from the inbox', $inboxViewTestContents, 'frontend test covers clear-all header behavior');

    logInfo('Phase B: Verify pruning retention behavior using command execution');

    NotificationLog::query()->delete();

    $pruneNow = CarbonImmutable::parse('2026-02-13T12:00:00Z');
    Carbon::setTestNow($pruneNow);
    CarbonImmutable::setTestNow($pruneNow);

    try {
        $oldLog = NotificationLog::factory()->create([
            'sent_at' => $pruneNow->subDays(31),
            'read_at' => null,
            'dismissed_at' => null,
            'status' => 'delivered',
        ]);

        $boundaryLog = NotificationLog::factory()->create([
            'sent_at' => $pruneNow->subDays(30),
            'read_at' => null,
            'dismissed_at' => null,
            'status' => 'delivered',
        ]);

        $recentLog = NotificationLog::factory()->create([
            'sent_at' => $pruneNow->subDays(7),
            'read_at' => null,
            'dismissed_at' => null,
            'status' => 'delivered',
        ]);

        Artisan::call('notifications:prune');
        $firstPruneOutput = trim(Artisan::output());

        assertContainsText('Pruned 1 notification log(s) older than 30 days.', $firstPruneOutput, 'first prune run removes only stale rows');
        assertTrue(! NotificationLog::query()->whereKey($oldLog->id)->exists(), 'old (>30 days) log is deleted');
        assertTrue(NotificationLog::query()->whereKey($boundaryLog->id)->exists(), 'boundary (exactly 30 days) log is retained');
        assertTrue(NotificationLog::query()->whereKey($recentLog->id)->exists(), 'recent log is retained');

        Artisan::call('notifications:prune');
        $secondPruneOutput = trim(Artisan::output());
        assertContainsText('Pruned 0 notification log(s) older than 30 days.', $secondPruneOutput, 'second prune run reports zero deletions');
    } finally {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
    }

    logInfo('Phase C: Verify bulk inbox API behavior (mark-all-read and clear-all)');

    $controller = app(NotificationInboxController::class);

    $bulkUser = User::factory()->create();
    $bulkOtherUser = User::factory()->create();

    $unreadOne = NotificationLog::factory()->create([
        'user_id' => $bulkUser->id,
        'status' => 'delivered',
        'read_at' => null,
        'dismissed_at' => null,
        'sent_at' => CarbonImmutable::parse('2026-02-13T11:00:00Z'),
    ]);

    $unreadTwo = NotificationLog::factory()->create([
        'user_id' => $bulkUser->id,
        'status' => 'sent',
        'read_at' => null,
        'dismissed_at' => null,
        'sent_at' => CarbonImmutable::parse('2026-02-13T11:01:00Z'),
    ]);

    $alreadyReadAt = CarbonImmutable::parse('2026-02-13T10:30:00Z');
    $alreadyRead = NotificationLog::factory()->create([
        'user_id' => $bulkUser->id,
        'status' => 'read',
        'read_at' => $alreadyReadAt,
        'dismissed_at' => null,
        'sent_at' => CarbonImmutable::parse('2026-02-13T10:29:00Z'),
    ]);

    $alreadyDismissed = NotificationLog::factory()->create([
        'user_id' => $bulkUser->id,
        'status' => 'dismissed',
        'read_at' => CarbonImmutable::parse('2026-02-13T10:00:00Z'),
        'dismissed_at' => CarbonImmutable::parse('2026-02-13T10:00:30Z'),
        'sent_at' => CarbonImmutable::parse('2026-02-13T10:00:00Z'),
    ]);

    $otherUserUnread = NotificationLog::factory()->create([
        'user_id' => $bulkOtherUser->id,
        'status' => 'delivered',
        'read_at' => null,
        'dismissed_at' => null,
        'sent_at' => CarbonImmutable::parse('2026-02-13T11:02:00Z'),
    ]);

    $markAllReadRequest = makeRequest($bulkUser, 'PATCH', '/notifications/inbox/read-all');
    $markAllReadPayload = decodeJsonResponse(
        withBoundRequest($markAllReadRequest, fn () => $controller->markAllRead($markAllReadRequest))
    );

    assertEqual($markAllReadPayload['meta']['marked_read_count'] ?? null, 2, 'mark-all-read updates only unread undismissed rows');
    assertEqual($markAllReadPayload['meta']['unread_count'] ?? null, 0, 'mark-all-read returns zero unread rows after update');

    $unreadOne->refresh();
    $unreadTwo->refresh();
    $alreadyRead->refresh();
    $alreadyDismissed->refresh();
    $otherUserUnread->refresh();

    assertTrue($unreadOne->read_at !== null, 'mark-all-read sets read_at for unread row #1');
    assertEqual($unreadOne->status, 'read', 'mark-all-read sets read status for unread row #1');
    assertTrue($unreadTwo->read_at !== null, 'mark-all-read sets read_at for unread row #2');
    assertEqual($unreadTwo->status, 'read', 'mark-all-read sets read status for unread row #2');
    assertEqual($alreadyRead->read_at?->toISOString(), $alreadyReadAt->toISOString(), 'mark-all-read preserves existing read_at');
    assertEqual($alreadyRead->status, 'read', 'mark-all-read preserves already read status');
    assertEqual($alreadyDismissed->status, 'dismissed', 'mark-all-read does not alter dismissed rows');
    assertTrue($otherUserUnread->read_at === null, 'mark-all-read does not alter other users');

    $clearUser = User::factory()->create();
    $clearOtherUser = User::factory()->create();

    $clearUnread = NotificationLog::factory()->create([
        'user_id' => $clearUser->id,
        'status' => 'delivered',
        'read_at' => null,
        'dismissed_at' => null,
        'sent_at' => CarbonImmutable::parse('2026-02-13T08:00:00Z'),
    ]);

    $clearExistingReadAt = CarbonImmutable::parse('2026-02-13T08:15:00Z');
    $clearRead = NotificationLog::factory()->create([
        'user_id' => $clearUser->id,
        'status' => 'read',
        'read_at' => $clearExistingReadAt,
        'dismissed_at' => null,
        'sent_at' => CarbonImmutable::parse('2026-02-13T08:10:00Z'),
    ]);

    $clearAlreadyDismissedAt = CarbonImmutable::parse('2026-02-13T08:20:00Z');
    $clearAlreadyDismissed = NotificationLog::factory()->create([
        'user_id' => $clearUser->id,
        'status' => 'dismissed',
        'read_at' => CarbonImmutable::parse('2026-02-13T08:19:30Z'),
        'dismissed_at' => $clearAlreadyDismissedAt,
        'sent_at' => CarbonImmutable::parse('2026-02-13T08:19:00Z'),
    ]);

    $clearOtherUserLog = NotificationLog::factory()->create([
        'user_id' => $clearOtherUser->id,
        'status' => 'delivered',
        'read_at' => null,
        'dismissed_at' => null,
        'sent_at' => CarbonImmutable::parse('2026-02-13T08:30:00Z'),
    ]);

    $clearNow = CarbonImmutable::parse('2026-02-13T09:00:00Z');
    Carbon::setTestNow($clearNow);
    CarbonImmutable::setTestNow($clearNow);

    try {
        $clearRequest = makeRequest($clearUser, 'DELETE', '/notifications/inbox');
        $clearPayload = decodeJsonResponse(
            withBoundRequest($clearRequest, fn () => $controller->clearAll($clearRequest))
        );
    } finally {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
    }

    assertEqual($clearPayload['meta']['dismissed_count'] ?? null, 2, 'clear-all dismisses only undismissed rows');
    assertEqual($clearPayload['meta']['unread_count'] ?? null, 0, 'clear-all unread count excludes dismissed rows');

    $clearUnread->refresh();
    $clearRead->refresh();
    $clearAlreadyDismissed->refresh();
    $clearOtherUserLog->refresh();

    assertEqual($clearUnread->status, 'dismissed', 'clear-all transitions delivered row to dismissed');
    assertEqual($clearUnread->read_at?->toDateTimeString(), $clearNow->toDateTimeString(), 'clear-all stamps read_at for previously unread row');
    assertEqual($clearUnread->dismissed_at?->toDateTimeString(), $clearNow->toDateTimeString(), 'clear-all stamps dismissed_at for previously unread row');

    assertEqual($clearRead->status, 'dismissed', 'clear-all transitions previously read row to dismissed');
    assertEqual($clearRead->read_at?->toISOString(), $clearExistingReadAt->toISOString(), 'clear-all preserves existing read_at on read row');
    assertEqual($clearRead->dismissed_at?->toDateTimeString(), $clearNow->toDateTimeString(), 'clear-all stamps dismissed_at on read row');

    assertEqual($clearAlreadyDismissed->dismissed_at?->toISOString(), $clearAlreadyDismissedAt->toISOString(), 'clear-all leaves already dismissed row untouched');
    assertEqual($clearOtherUserLog->status, 'delivered', 'clear-all does not alter other users');

    if ($txStarted && DB::connection()->transactionLevel() > 0) {
        DB::rollBack();
        $txStarted = false;
        logInfo('Transaction rolled back before command-based gates.');
    }

    logInfo('Protocol Step 3/5: Execute automated test commands');

    $commandPrefix = '';
    if ($usingSqliteFallback) {
        ensureSqliteDatabaseFile($sqliteSuitePath);
        $commandPrefix = 'DB_CONNECTION=sqlite DB_DATABASE='.escapeshellarg($sqliteSuitePath).' ';
    }

    $commands = [
        [
            'label' => 'PruneNotificationsCommandTest',
            'command' => $commandPrefix.'CI=true php artisan test --filter=PruneNotificationsCommandTest',
        ],
        [
            'label' => 'NotificationPruningScheduleTest',
            'command' => $commandPrefix.'CI=true php artisan test --filter=NotificationPruningScheduleTest',
        ],
        [
            'label' => 'NotificationInboxControllerTest',
            'command' => $commandPrefix.'CI=true php artisan test --filter=NotificationInboxControllerTest',
        ],
        [
            'label' => 'NotificationInboxView frontend vitest suite',
            'command' => 'CI=true LARAVEL_BYPASS_ENV_CHECK=1 pnpm exec vitest run resources/js/features/gta-alerts/components/NotificationInboxView.test.tsx',
        ],
    ];

    foreach ($commands as $command) {
        $result = runCommand($command['command'], $command['label']);
        assertTrue(
            $result['exit_code'] === 0,
            "command passes: {$command['label']}",
            ['exit_code' => $result['exit_code']]
        );
    }

    logInfo('Protocol Step 4/5: Proposed manual browser verification plan');
    $manualPlan = [
        [
            'step' => 1,
            'command' => './vendor/bin/sail up -d && ./vendor/bin/sail pnpm run dev',
            'expected' => 'Laravel app and Vite dev server are available without startup errors.',
        ],
        [
            'step' => 2,
            'command' => './vendor/bin/sail artisan notifications:prune',
            'expected' => 'CLI prints "Pruned N notification log(s) older than 30 days." with N >= 0.',
        ],
        [
            'step' => 3,
            'command' => 'Sign in, open GTA Alerts page, then open Notification Center.',
            'expected' => 'Header shows both "Mark all read" and "Clear all" actions.',
        ],
        [
            'step' => 4,
            'command' => 'With at least one unread entry, click "Mark all read" once.',
            'expected' => 'Unread badge updates to 0, row-level "Mark as read" actions disappear/disable while request is in flight.',
        ],
        [
            'step' => 5,
            'command' => 'While mark-all is in progress, try row dismiss and "Load older notifications".',
            'expected' => 'Both actions stay disabled; no duplicate/extra requests are sent.',
        ],
        [
            'step' => 6,
            'command' => 'Click "Clear all" from the inbox header.',
            'expected' => 'Visible inbox clears optimistically and remains empty after API success.',
        ],
        [
            'step' => 7,
            'command' => 'In browser network panel, inspect requests for /notifications/inbox/read-all and DELETE /notifications/inbox.',
            'expected' => 'Both endpoints return 200 with JSON meta payloads (marked_read_count/unread_count and dismissed_count/unread_count).',
        ],
    ];

    foreach ($manualPlan as $entry) {
        logInfo('Manual step', $entry);
    }

    logInfo('Protocol Step 5/5: Await explicit user verification feedback outside script execution', [
        'expected_feedback' => 'yes/no plus any UI regressions observed during browser verification',
    ]);

    logInfo('=== Manual Test Completed Successfully ===');
} catch (Throwable $e) {
    $exitCode = 1;
    logError('Manual Test Failed', [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
} finally {
    if ($txStarted) {
        try {
            if (DB::connection()->transactionLevel() > 0) {
                DB::rollBack();
                logInfo('Transaction rolled back (Database preserved).');
            }
        } catch (Throwable) {
        }
    }

    if ($usingSqliteFallback && file_exists($sqliteFallbackPath)) {
        @unlink($sqliteFallbackPath);
    }

    if ($usingSqliteFallback && file_exists($sqliteSuitePath)) {
        @unlink($sqliteSuitePath);
    }

    logInfo('Manual test log file', ['path' => $logFileRelative]);
}

exit($exitCode);
