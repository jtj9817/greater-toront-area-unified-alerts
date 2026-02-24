<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

class ImportAlertDataSql extends Command
{
    protected $signature = 'db:import-sql
                            {--file= : Path to the .sql file to import}
                            {--force : Skip confirmation prompt}
                            {--dry-run : Parse and validate the file without executing SQL}
                            {--allow-testing : Allow execution when APP_ENV=testing or database is *_testing}';

    protected $description = 'Import alert SQL data using psql with data-only safety checks';

    public function handle(): int
    {
        try {
            $filePath = $this->resolveFilePath();
            $connection = $this->resolveDatabaseConnection();
            $database = $this->resolveConnectionValue($connection, 'database', 'database');

            if (! $this->option('allow-testing') && $this->isTestingTarget($database)) {
                $this->error('Refusing to import while APP_ENV is testing. Re-run with --allow-testing to override.');

                return self::FAILURE;
            }

            if (str_ends_with(strtolower($filePath), '.gz')) {
                $hostForHint = $this->resolveConnectionValueOrDefault($connection, 'host', '<host>');
                $portForHint = $this->resolveConnectionValueOrDefault($connection, 'port', '<port>');
                $usernameForHint = $this->resolveConnectionValueOrDefault($connection, 'username', '<username>');
                $this->error('Compressed SQL files are not supported by db:import-sql.');
                $this->line("Use: gunzip -c {$filePath} | psql -h {$hostForHint} -p {$portForHint} -U {$usernameForHint} -d {$database}");

                return self::FAILURE;
            }

            $this->ensureReadableSqlFile($filePath);

            if ($this->option('dry-run')) {
                $this->validateSqlFileForDryRun($filePath);
                $this->info('Dry-run validation passed.');

                return self::SUCCESS;
            }

            $host = $this->resolveConnectionValue($connection, 'host', 'host');
            $port = $this->resolveConnectionValue($connection, 'port', 'port');
            $username = $this->resolveConnectionValue($connection, 'username', 'username');
            $password = (string) ($connection['password'] ?? '');

            if (! $this->option('force')) {
                $confirmed = $this->confirm(
                    "You are about to import SQL into database \"{$database}\" on host \"{$host}\". Continue?"
                );

                if (! $confirmed) {
                    $this->warn('Import aborted.');

                    return self::FAILURE;
                }
            }

            $command = [
                'psql',
                "--host={$host}",
                "--port={$port}",
                "--username={$username}",
                "--dbname={$database}",
                '--set=ON_ERROR_STOP=1',
                "--file={$filePath}",
            ];

            $result = Process::forever()->env([
                'PGPASSWORD' => $password,
            ])->run($command);

            if ($result->successful()) {
                $this->info('SQL import completed successfully.');

                return self::SUCCESS;
            }

            if ($this->isPsqlMissingResult($result->errorOutput(), $result->output(), $result->exitCode())) {
                $this->error('Import failed: psql CLI binary is required. Install PostgreSQL client tools or run via ./vendor/bin/sail artisan db:import-sql.');

                return self::FAILURE;
            }

            $errorOutput = trim($result->errorOutput());

            if ($errorOutput === '') {
                $errorOutput = trim($result->output());
            }

            $message = $errorOutput !== ''
                ? "Import failed: {$errorOutput}"
                : 'Import failed: psql returned a non-zero exit code.';

            $this->error($message);

            return self::FAILURE;
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            if ($this->isPsqlMissingThrowable($exception)) {
                $this->error('Import failed: psql CLI binary is required. Install PostgreSQL client tools or run via ./vendor/bin/sail artisan db:import-sql.');

                return self::FAILURE;
            }

            $this->error("Import failed: {$exception->getMessage()}");

            return self::FAILURE;
        }
    }

    private function resolveFilePath(): string
    {
        $option = $this->option('file');

        if (! is_string($option) || trim($option) === '') {
            throw new RuntimeException('Import failed: --file is required.');
        }

        $filePath = trim($option);

        if ($this->isAbsolutePath($filePath)) {
            return $filePath;
        }

        return base_path($filePath);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveDatabaseConnection(): array
    {
        $connectionName = config('database.default');

        if (! is_string($connectionName) || $connectionName === '') {
            throw new RuntimeException('Import failed: unable to determine default database connection.');
        }

        $connection = config("database.connections.{$connectionName}");

        if (! is_array($connection)) {
            throw new RuntimeException("Import failed: database connection [{$connectionName}] is not configured.");
        }

        return $connection;
    }

    private function resolveConnectionValue(array $connection, string $key, string $label): string
    {
        $value = $connection[$key] ?? null;

        if (! is_string($value) && ! is_int($value)) {
            throw new RuntimeException("Import failed: database connection is missing {$label}.");
        }

        $resolved = trim((string) $value);

        if ($resolved === '') {
            throw new RuntimeException("Import failed: database connection is missing {$label}.");
        }

        return $resolved;
    }

    private function resolveConnectionValueOrDefault(array $connection, string $key, string $default): string
    {
        $value = $connection[$key] ?? null;

        if (! is_string($value) && ! is_int($value)) {
            return $default;
        }

        $resolved = trim((string) $value);

        if ($resolved === '') {
            return $default;
        }

        return $resolved;
    }

    private function isTestingTarget(string $database): bool
    {
        return App::environment('testing') || str_ends_with(strtolower($database), '_testing');
    }

    private function ensureReadableSqlFile(string $filePath): void
    {
        if (! is_file($filePath)) {
            throw new RuntimeException("Import failed: SQL file not found: {$filePath}");
        }

        if (! is_readable($filePath)) {
            throw new RuntimeException("Import failed: SQL file is not readable: {$filePath}");
        }

        $size = filesize($filePath);

        if ($size === false || $size <= 0) {
            throw new RuntimeException("Import failed: SQL file is empty: {$filePath}");
        }
    }

    private function validateSqlFileForDryRun(string $filePath): void
    {
        $handle = fopen($filePath, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Dry-run failed: unable to open SQL file: {$filePath}");
        }

        $statement = '';
        $inSingleQuote = false;
        $hasStatement = false;

        try {
            while (($line = fgets($handle)) !== false) {
                $lineLength = strlen($line);

                for ($index = 0; $index < $lineLength; $index++) {
                    $char = $line[$index];
                    $next = $line[$index + 1] ?? '';

                    if (! $inSingleQuote && $char === '-' && $next === '-') {
                        break;
                    }

                    if ($char === "'") {
                        if ($inSingleQuote && $next === "'") {
                            $statement .= "''";
                            $index++;

                            continue;
                        }

                        $inSingleQuote = ! $inSingleQuote;
                    }

                    $statement .= $char;

                    if (! $inSingleQuote && $char === ';') {
                        $this->validateStatement($statement);
                        $hasStatement = true;
                        $statement = '';
                    }
                }

                if ($statement === '' && trim($line) === '') {
                    continue;
                }

                $statement .= "\n";
            }

            if (trim($statement) !== '') {
                $this->validateStatement($statement);
                $hasStatement = true;
            }
        } finally {
            fclose($handle);
        }

        if (! $hasStatement) {
            throw new RuntimeException('Dry-run failed: SQL file contains no executable statements.');
        }
    }

    private function validateStatement(string $statement): void
    {
        $trimmed = trim($statement);

        if ($trimmed === '' || str_starts_with($trimmed, '--')) {
            return;
        }

        $withoutStrings = $this->stripSqlStringLiterals($trimmed);

        if (preg_match('/\b(drop|create|alter|truncate|comment|rename)\b/i', $withoutStrings) === 1) {
            throw new RuntimeException('Dry-run failed: DDL statements are not allowed.');
        }

        $normalized = strtoupper(ltrim($trimmed));

        if (str_starts_with($normalized, 'SET ') || str_starts_with($normalized, 'INSERT ')) {
            return;
        }

        if (str_starts_with($normalized, 'SELECT ') && preg_match('/\bsetval\s*\(/i', $withoutStrings) === 1) {
            return;
        }

        throw new RuntimeException(
            'Dry-run failed: unsupported SQL statement detected. Allowed statements: INSERT, SET, comments, and SELECT setval(...).'
        );
    }

    private function stripSqlStringLiterals(string $sql): string
    {
        $result = '';
        $inSingleQuote = false;
        $length = strlen($sql);

        for ($index = 0; $index < $length; $index++) {
            $char = $sql[$index];
            $next = $sql[$index + 1] ?? '';

            if ($char === "'") {
                if ($inSingleQuote && $next === "'") {
                    $result .= '  ';
                    $index++;

                    continue;
                }

                $inSingleQuote = ! $inSingleQuote;
                $result .= ' ';

                continue;
            }

            $result .= $inSingleQuote ? ' ' : $char;
        }

        return $result;
    }

    private function isPsqlMissingResult(string $errorOutput, string $output, int $exitCode): bool
    {
        if ($exitCode === 127) {
            return true;
        }

        $haystack = strtolower($errorOutput.' '.$output);

        return str_contains($haystack, 'psql: command not found')
            || str_contains($haystack, "'psql' is not recognized")
            || str_contains($haystack, 'executable file not found');
    }

    private function isPsqlMissingThrowable(Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'psql')
            && (
                str_contains($message, 'not found')
                || str_contains($message, 'not recognized')
                || str_contains($message, 'unable to launch')
                || str_contains($message, 'failed to start')
            );
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1;
    }
}
