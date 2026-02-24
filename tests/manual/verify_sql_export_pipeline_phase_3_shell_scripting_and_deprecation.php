<?php

/**
 * Manual Test: SQL Export Pipeline - Phase 3 Shell Scripting and Deprecation
 * Generated: 2026-02-24
 *
 * Purpose:
 * - Verify `scripts/export-alert-data.sh` runner modes (`--no-sail`, `--sail`, auto fallback).
 * - Verify transfer/import guidance output from the shell workflow.
 * - Verify seeder workflow deprecation markers and documentation updates.
 *
 * Run:
 * - ./vendor/bin/sail php tests/manual/verify_sql_export_pipeline_phase_3_shell_scripting_and_deprecation.php
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
// Use a deterministic testing-only fallback so app boot does not fail.
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

if (! app()->environment('testing')) {
    exit("Error: Manual tests must run with APP_ENV=testing.\n");
}

umask(002);

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

$testRunId = 'sql_export_pipeline_phase_3_shell_scripting_and_deprecation_'.Carbon::now()->format('Y_m_d_His');
$logFileRelative = "storage/logs/manual_tests/{$testRunId}.log";
$logFile = storage_path("logs/manual_tests/{$testRunId}.log");
$logDir = dirname($logFile);
$workingDir = storage_path("app/private/manual_tests/{$testRunId}");

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

// Route app warnings/errors under test to this manual log file.
config(['logging.default' => 'manual_test']);

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

function assertTrueManual(bool $condition, string $label, array $ctx = []): void
{
    if (! $condition) {
        $message = "Assertion failed: {$label}.";
        logError($message, $ctx);
        throw new RuntimeException($message);
    }

    logInfo("Assertion passed: {$label}");
}

function assertContainsManual(string $needle, string $haystack, string $label): void
{
    assertTrueManual(str_contains($haystack, $needle), $label, ['needle' => $needle]);
}

function deleteDirectoryRecursivelyManual(string $directory): void
{
    if (! is_dir($directory)) {
        return;
    }

    $entries = glob($directory.'/*');

    if (is_array($entries)) {
        foreach ($entries as $entry) {
            if (is_dir($entry)) {
                deleteDirectoryRecursivelyManual($entry);
            } else {
                @unlink($entry);
            }
        }
    }

    @rmdir($directory);
}

function ensureDirectoryExistsManual(string $directory): void
{
    if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
        throw new RuntimeException("Unable to create directory: {$directory}");
    }
}

function writeExecutableFileManual(string $path, string $contents): void
{
    ensureDirectoryExistsManual(dirname($path));

    $written = file_put_contents($path, $contents);

    if ($written === false) {
        throw new RuntimeException("Failed to write file: {$path}");
    }

    if (! @chmod($path, 0775)) {
        throw new RuntimeException("Failed to set executable permissions: {$path}");
    }
}

function readFileContentsManual(string $path): string
{
    $contents = file_get_contents($path);

    if (! is_string($contents)) {
        throw new RuntimeException("Failed to read file: {$path}");
    }

    return $contents;
}

/**
 * @param  array<string, string>  $env
 * @return array{exit_code: int, output: string}
 */
function runShellCommandManual(string $command, string $cwd, array $env = []): array
{
    logInfo('Running shell command', [
        'command' => $command,
        'cwd' => $cwd,
    ]);

    $process = Process::fromShellCommandline($command, $cwd, $env);
    $process->setTimeout(60);
    $process->run();

    $output = $process->getOutput().$process->getErrorOutput();
    $exitCode = $process->getExitCode();

    Log::channel('manual_test')->info('Command finished', [
        'command' => $command,
        'cwd' => $cwd,
        'exit_code' => $exitCode,
        'output' => $output,
    ]);

    return [
        'exit_code' => $exitCode ?? 1,
        'output' => $output,
    ];
}

/**
 * @return array{
 *   fake_repo: string,
 *   fake_bin: string
 * }
 */
function createFakeRunnerHarnessManual(string $workingDir): array
{
    $fakeRepo = $workingDir.'/fake-repo';
    $fakeBin = $fakeRepo.'/bin';
    $scriptsDir = $fakeRepo.'/scripts';
    $vendorBinDir = $fakeRepo.'/vendor/bin';

    ensureDirectoryExistsManual($fakeRepo);
    ensureDirectoryExistsManual($fakeBin);
    ensureDirectoryExistsManual($scriptsDir);
    ensureDirectoryExistsManual($vendorBinDir);
    ensureDirectoryExistsManual($fakeRepo.'/exports');
    ensureDirectoryExistsManual($fakeRepo.'/storage/app');

    $sourceScript = base_path('scripts/export-alert-data.sh');
    $targetScript = $scriptsDir.'/export-alert-data.sh';
    $copied = copy($sourceScript, $targetScript);
    assertTrueManual($copied, 'export-alert-data.sh copied into fake harness');
    @chmod($targetScript, 0775);

    $artisanPath = $fakeRepo.'/artisan';
    writeExecutableFileManual($artisanPath, "#!/usr/bin/env bash\necho \"Fake artisan entrypoint\"\n");

    $phpStub = <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail

LOG_DIR="${FAKE_RUNNER_LOG_DIR:-}"
if [[ -n "${LOG_DIR}" ]]; then
    mkdir -p "${LOG_DIR}"
    printf '%s\n' "$*" >> "${LOG_DIR}/php_calls.log"
fi

if [[ "${1:-}" == "artisan" && "${2:-}" == "--version" ]]; then
    echo "Laravel Framework 11.x"
    exit 0
fi

if [[ "${1:-}" != "artisan" ]]; then
    echo "Unsupported php invocation: $*" >&2
    exit 1
fi

shift
command_name="${1:-}"
if [[ -n "${command_name}" ]]; then
    shift
fi

if [[ "${command_name}" != "db:export-sql" ]]; then
    echo "Unsupported artisan command: ${command_name}" >&2
    exit 1
fi

if [[ "${FAIL_LOCAL_EXPORT:-0}" == "1" ]]; then
    echo "Simulated local export failure." >&2
    exit 1
fi

output_path="storage/app/alert-export.sql"
compress=0

for arg in "$@"; do
    case "${arg}" in
        --output=*)
            output_path="${arg#--output=}"
            ;;
        --compress)
            compress=1
            ;;
    esac
done

if [[ "${compress}" -eq 1 && "${output_path}" != *.gz ]]; then
    output_path="${output_path}.gz"
fi

mkdir -p "$(dirname "${output_path}")"

if [[ "${compress}" -eq 1 ]]; then
    printf '%s\n' "fake compressed local export" | gzip -c > "${output_path}"
else
    cat > "${output_path}" <<'SQL'
SET client_encoding = 'UTF8';
INSERT INTO "fire_incidents" ("id") VALUES (1) ON CONFLICT (id) DO NOTHING;
SQL
fi

echo "fake local export completed"
BASH;
    writeExecutableFileManual($fakeBin.'/php', $phpStub);

    $sailStub = <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail

LOG_DIR="${FAKE_RUNNER_LOG_DIR:-}"
if [[ -n "${LOG_DIR}" ]]; then
    mkdir -p "${LOG_DIR}"
    printf '%s\n' "$*" >> "${LOG_DIR}/sail_calls.log"
fi

if [[ "${1:-}" != "artisan" ]]; then
    echo "Unsupported sail invocation: $*" >&2
    exit 1
fi

shift
command_name="${1:-}"
if [[ -n "${command_name}" ]]; then
    shift
fi

if [[ "${command_name}" != "db:export-sql" ]]; then
    echo "Unsupported artisan command via sail: ${command_name}" >&2
    exit 1
fi

output_path="storage/app/alert-export.sql"
compress=0

for arg in "$@"; do
    case "${arg}" in
        --output=*)
            output_path="${arg#--output=}"
            ;;
        --compress)
            compress=1
            ;;
    esac
done

if [[ "${compress}" -eq 1 && "${output_path}" != *.gz ]]; then
    output_path="${output_path}.gz"
fi

mkdir -p "$(dirname "${output_path}")"

if [[ "${compress}" -eq 1 ]]; then
    printf '%s\n' "fake compressed sail export" | gzip -c > "${output_path}"
else
    cat > "${output_path}" <<'SQL'
SET client_encoding = 'UTF8';
INSERT INTO "police_calls" ("id") VALUES (1) ON CONFLICT (id) DO NOTHING;
SQL
fi

echo "fake sail export completed"
BASH;
    writeExecutableFileManual($fakeRepo.'/vendor/bin/sail', $sailStub);

    return [
        'fake_repo' => $fakeRepo,
        'fake_bin' => $fakeBin,
    ];
}

$exitCode = 0;

try {
    logInfo('=== Starting Manual Test: SQL Export Pipeline Phase 3 ===', [
        'app_env' => app()->environment(),
    ]);

    ensureDirectoryExistsManual($workingDir);

    $repoRoot = base_path();

    logInfo('Step 1: Verify Phase 3 artifacts exist.');

    $exportShellScript = $repoRoot.'/scripts/export-alert-data.sh';
    $legacyShellScript = $repoRoot.'/scripts/generate-production-seed.sh';
    $exportProductionCommand = $repoRoot.'/app/Console/Commands/ExportProductionData.php';
    $verifyProductionSeedCommand = $repoRoot.'/app/Console/Commands/VerifyProductionSeed.php';
    $scriptsReadme = $repoRoot.'/scripts/README.md';

    assertTrueManual(file_exists($exportShellScript), 'scripts/export-alert-data.sh exists');
    assertTrueManual(is_executable($exportShellScript), 'scripts/export-alert-data.sh is executable');
    assertTrueManual(file_exists($legacyShellScript), 'scripts/generate-production-seed.sh exists');
    assertTrueManual(file_exists($exportProductionCommand), 'ExportProductionData command exists');
    assertTrueManual(file_exists($verifyProductionSeedCommand), 'VerifyProductionSeed command exists');
    assertTrueManual(file_exists($scriptsReadme), 'scripts/README.md exists');

    logInfo('Step 2: Verify export helper usage text documents runner options and SQL workflow.');

    $helpResult = runShellCommandManual(
        'bash scripts/export-alert-data.sh --help',
        $repoRoot
    );

    assertTrueManual($helpResult['exit_code'] === 0, 'export-alert-data.sh --help exits successfully', [
        'output' => $helpResult['output'],
    ]);
    assertContainsManual('Runs `db:export-sql` and prints file transfer/import guidance', $helpResult['output'], 'help describes SQL export workflow');
    assertContainsManual('--sail', $helpResult['output'], 'help includes --sail option');
    assertContainsManual('--no-sail', $helpResult['output'], 'help includes --no-sail option');
    assertContainsManual('--compress', $helpResult['output'], 'help includes --compress option');

    logInfo('Step 3: Execute shell helper in a fake harness using local runner mode.');

    $harness = createFakeRunnerHarnessManual($workingDir);
    $fakeRepo = $harness['fake_repo'];
    $fakeBin = $harness['fake_bin'];
    $runnerPath = $fakeBin.':'.(getenv('PATH') ?: '');
    $localLogDir = $workingDir.'/runner-logs/local';

    ensureDirectoryExistsManual($localLogDir);

    $localResult = runShellCommandManual(
        'bash scripts/export-alert-data.sh --no-sail --output exports/local-phase3.sql --chunk 123 --tables fire_incidents --no-header',
        $fakeRepo,
        [
            'PATH' => $runnerPath,
            'FAKE_RUNNER_LOG_DIR' => $localLogDir,
        ]
    );

    assertTrueManual($localResult['exit_code'] === 0, 'local runner export command exits successfully', [
        'output' => $localResult['output'],
    ]);
    assertContainsManual('Using Artisan runner: php artisan', $localResult['output'], 'local mode uses php artisan runner');
    assertContainsManual('Transfer instructions:', $localResult['output'], 'local mode prints transfer instructions');
    assertContainsManual(
        'php artisan db:import-sql --file=/path/to/local-phase3.sql --force',
        $localResult['output'],
        'local mode prints uncompressed import instruction'
    );
    assertTrueManual(
        file_exists($fakeRepo.'/exports/local-phase3.sql'),
        'local mode generates export file in selected output path'
    );

    $localPhpCallsLog = readFileContentsManual($localLogDir.'/php_calls.log');
    assertContainsManual('artisan db:export-sql', $localPhpCallsLog, 'local mode invokes db:export-sql');
    assertContainsManual('--output=exports/local-phase3.sql', $localPhpCallsLog, 'local mode forwards --output');
    assertContainsManual('--chunk=123', $localPhpCallsLog, 'local mode forwards --chunk');
    assertContainsManual('--tables=fire_incidents', $localPhpCallsLog, 'local mode forwards --tables');
    assertContainsManual('--no-header', $localPhpCallsLog, 'local mode forwards --no-header');

    logInfo('Step 4: Execute shell helper in fake harness using Sail mode and gzip output.');

    $sailLogDir = $workingDir.'/runner-logs/sail';
    ensureDirectoryExistsManual($sailLogDir);

    $sailResult = runShellCommandManual(
        'bash scripts/export-alert-data.sh --sail --compress --output exports/sail-phase3.sql --chunk 200',
        $fakeRepo,
        [
            'PATH' => $runnerPath,
            'FAKE_RUNNER_LOG_DIR' => $sailLogDir,
        ]
    );

    assertTrueManual($sailResult['exit_code'] === 0, 'sail mode export command exits successfully', [
        'output' => $sailResult['output'],
    ]);
    assertContainsManual(
        'Using Artisan runner: ./vendor/bin/sail artisan',
        $sailResult['output'],
        'sail mode uses sail artisan runner'
    );
    assertTrueManual(
        file_exists($fakeRepo.'/exports/sail-phase3.sql.gz'),
        'sail mode with --compress generates .sql.gz output'
    );
    assertContainsManual(
        'gunzip -c sail-phase3.sql.gz > sail-phase3.sql',
        $sailResult['output'],
        'sail mode prints gzip decompression guidance'
    );
    assertContainsManual(
        'php artisan db:import-sql --file=/path/to/sail-phase3.sql --force',
        $sailResult['output'],
        'sail mode prints decompressed import instruction'
    );

    $sailCallsLog = readFileContentsManual($sailLogDir.'/sail_calls.log');
    assertContainsManual('artisan db:export-sql', $sailCallsLog, 'sail mode invokes db:export-sql');
    assertContainsManual('--output=exports/sail-phase3.sql', $sailCallsLog, 'sail mode forwards --output');
    assertContainsManual('--chunk=200', $sailCallsLog, 'sail mode forwards --chunk');
    assertContainsManual('--compress', $sailCallsLog, 'sail mode forwards --compress');

    logInfo('Step 5: Verify auto mode falls back to Sail when local execution fails.');

    $fallbackLogDir = $workingDir.'/runner-logs/fallback';
    ensureDirectoryExistsManual($fallbackLogDir);

    $fallbackResult = runShellCommandManual(
        'bash scripts/export-alert-data.sh --output exports/fallback-phase3.sql --chunk 111',
        $fakeRepo,
        [
            'PATH' => $runnerPath,
            'FAKE_RUNNER_LOG_DIR' => $fallbackLogDir,
            'FAIL_LOCAL_EXPORT' => '1',
        ]
    );

    assertTrueManual($fallbackResult['exit_code'] === 0, 'auto mode fallback export exits successfully', [
        'output' => $fallbackResult['output'],
    ]);
    assertContainsManual(
        'Local export failed in auto mode; retrying with Sail.',
        $fallbackResult['output'],
        'auto mode prints fallback notice'
    );
    assertContainsManual(
        'Using Artisan runner: ./vendor/bin/sail artisan',
        $fallbackResult['output'],
        'auto mode switches to sail runner after failure'
    );
    assertTrueManual(
        file_exists($fakeRepo.'/exports/fallback-phase3.sql'),
        'auto mode fallback produces export output file'
    );

    $fallbackPhpCallsLog = readFileContentsManual($fallbackLogDir.'/php_calls.log');
    $fallbackSailCallsLog = readFileContentsManual($fallbackLogDir.'/sail_calls.log');
    assertContainsManual('artisan db:export-sql', $fallbackPhpCallsLog, 'auto mode attempted local db:export-sql');
    assertContainsManual('artisan db:export-sql', $fallbackSailCallsLog, 'auto mode retried db:export-sql through sail');

    logInfo('Step 6: Verify deprecation markers and documentation content.');

    $exportProductionContents = readFileContentsManual($exportProductionCommand);
    $verifyProductionSeedContents = readFileContentsManual($verifyProductionSeedCommand);
    $legacyScriptContents = readFileContentsManual($legacyShellScript);
    $scriptsReadmeContents = readFileContentsManual($scriptsReadme);

    assertContainsManual('@deprecated', $exportProductionContents, 'ExportProductionData command has @deprecated docblock');
    assertContainsManual('db:export-sql', $exportProductionContents, 'ExportProductionData docblock points to SQL export command');

    assertContainsManual('@deprecated', $verifyProductionSeedContents, 'VerifyProductionSeed command has @deprecated docblock');
    assertContainsManual('db:import-sql', $verifyProductionSeedContents, 'VerifyProductionSeed docblock points to SQL import workflow');

    $legacyHelpResult = runShellCommandManual(
        'bash scripts/generate-production-seed.sh --help',
        $repoRoot
    );
    assertTrueManual($legacyHelpResult['exit_code'] === 0, 'legacy seeder script help exits successfully');
    assertContainsManual(
        'DEPRECATED: This workflow is superseded by SQL export/import.',
        $legacyHelpResult['output'],
        'legacy script help includes deprecation notice'
    );
    assertContainsManual(
        './scripts/export-alert-data.sh + php artisan db:import-sql for new transfers.',
        $legacyHelpResult['output'],
        'legacy script help points to replacement workflow'
    );
    assertContainsManual(
        'DEPRECATED: Seeder export workflow is superseded.',
        $legacyScriptContents,
        'legacy script runtime warnings include deprecation notice'
    );

    assertContainsManual('## SQL Export Pipeline (Preferred)', $scriptsReadmeContents, 'scripts README documents preferred SQL workflow');
    assertContainsManual('## Legacy Seeder Workflow (Deprecated)', $scriptsReadmeContents, 'scripts README marks legacy workflow as deprecated');
    assertContainsManual('db:export-to-seeder', $scriptsReadmeContents, 'scripts README still documents legacy command availability');
    assertContainsManual('db:import-sql', $scriptsReadmeContents, 'scripts README references SQL import command');

    logInfo('=== Manual Test Completed Successfully ===');
} catch (Throwable $e) {
    $exitCode = 1;

    logError('Manual Test Failed', [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
} finally {
    deleteDirectoryRecursivelyManual($workingDir);

    logInfo('=== Test Run Finished ===');

    if ($exitCode === 0) {
        echo "\nResult: PASS\nLogs at: {$logFileRelative}\n";
    } else {
        echo "\nResult: FAIL\nLogs at: {$logFileRelative}\n";
    }

    exit($exitCode);
}
