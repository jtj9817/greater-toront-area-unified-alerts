<?php

use Illuminate\Support\Facades\Process;

function makeSqlImportPath(string $contents, string $extension = '.sql'): string
{
    $path = sys_get_temp_dir().'/alert-import-'.bin2hex(random_bytes(6)).$extension;
    file_put_contents($path, $contents);

    return $path;
}

function configureImportPostgresConnection(string $database = 'gta_alerts'): void
{
    config()->set('database.default', 'pgsql');
    config()->set('database.connections.pgsql', [
        'driver' => 'pgsql',
        'host' => 'db.internal',
        'port' => '5432',
        'database' => $database,
        'username' => 'alerts_user',
        'password' => 'super-secret',
    ]);
}

function configureImportSqliteConnection(): void
{
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);
}

test('db import sql dry run rejects ddl statements', function () {
    configureImportPostgresConnection();

    $filePath = makeSqlImportPath(implode("\n", [
        '-- GTA Alerts SQL Export',
        "SET client_encoding = 'UTF8';",
        'INSERT INTO "fire_incidents" ("id") VALUES (1) ON CONFLICT (id) DO NOTHING;',
        'DROP TABLE fire_incidents;',
    ]));

    Process::fake();

    try {
        $this->artisan('db:import-sql', [
            '--file' => $filePath,
            '--dry-run' => true,
            '--allow-testing' => true,
        ])
            ->expectsOutputToContain('Dry-run failed: DDL statements are not allowed')
            ->assertExitCode(1);

        Process::assertNothingRan();
    } finally {
        @unlink($filePath);
    }
});

test('db import sql dry run does not require postgres network credentials', function () {
    configureImportSqliteConnection();

    $filePath = makeSqlImportPath('INSERT INTO "fire_incidents" ("id") VALUES (1) ON CONFLICT (id) DO NOTHING;');

    Process::fake();

    try {
        $this->artisan('db:import-sql', [
            '--file' => $filePath,
            '--dry-run' => true,
            '--allow-testing' => true,
        ])
            ->expectsOutputToContain('Dry-run validation passed.')
            ->assertExitCode(0);

        Process::assertNothingRan();
    } finally {
        @unlink($filePath);
    }
});

test('db import sql reports missing psql binary', function () {
    configureImportPostgresConnection();

    $filePath = makeSqlImportPath('INSERT INTO "fire_incidents" ("id") VALUES (1) ON CONFLICT (id) DO NOTHING;');

    Process::fake(fn () => Process::result(
        output: '',
        errorOutput: 'sh: psql: command not found',
        exitCode: 127,
    ));

    try {
        $this->artisan('db:import-sql', [
            '--file' => $filePath,
            '--force' => true,
            '--allow-testing' => true,
        ])
            ->expectsOutputToContain('Import failed: psql CLI binary is required')
            ->assertExitCode(1);

        Process::assertRan(function ($process) use ($filePath): bool {
            if (! is_array($process->command)) {
                return false;
            }

            return $process->command[0] === 'psql'
                && in_array('--file='.$filePath, $process->command, true)
                && $process->timeout === null
                && (($process->environment['PGPASSWORD'] ?? null) === 'super-secret');
        });
    } finally {
        @unlink($filePath);
    }
});

test('db import sql requires confirmation unless force is provided', function () {
    configureImportPostgresConnection();

    $filePath = makeSqlImportPath('INSERT INTO "fire_incidents" ("id") VALUES (1) ON CONFLICT (id) DO NOTHING;');

    Process::fake();

    try {
        $this->artisan('db:import-sql', [
            '--file' => $filePath,
            '--allow-testing' => true,
        ])
            ->expectsConfirmation('You are about to import SQL into database "gta_alerts" on host "db.internal". Continue?', 'no')
            ->expectsOutputToContain('Import aborted.')
            ->assertExitCode(1);

        Process::assertNothingRan();
    } finally {
        @unlink($filePath);
    }
});

test('db import sql executes without prompt when force is provided', function () {
    configureImportPostgresConnection();

    $filePath = makeSqlImportPath('INSERT INTO "fire_incidents" ("id") VALUES (1) ON CONFLICT (id) DO NOTHING;');

    Process::fake(fn () => Process::result(
        output: 'imported',
        errorOutput: '',
        exitCode: 0,
    ));

    try {
        $this->artisan('db:import-sql', [
            '--file' => $filePath,
            '--force' => true,
            '--allow-testing' => true,
        ])
            ->expectsOutputToContain('SQL import completed successfully.')
            ->assertExitCode(0);

        Process::assertRan(function ($process) use ($filePath): bool {
            if (! is_array($process->command)) {
                return false;
            }

            return $process->command[0] === 'psql'
                && in_array('--file='.$filePath, $process->command, true);
        });
    } finally {
        @unlink($filePath);
    }
});

test('db import sql rejects gzip input and prints decompression guidance', function () {
    configureImportPostgresConnection();

    $filePath = makeSqlImportPath('', '.sql.gz');

    Process::fake();

    try {
        $this->artisan('db:import-sql', [
            '--file' => $filePath,
            '--force' => true,
            '--allow-testing' => true,
        ])
            ->expectsOutputToContain('Compressed SQL files are not supported by db:import-sql.')
            ->expectsOutputToContain("gunzip -c {$filePath} | psql -h db.internal -p 5432 -U alerts_user -d gta_alerts")
            ->assertExitCode(1);

        Process::assertNothingRan();
    } finally {
        @unlink($filePath);
    }
});

test('db import sql refuses testing environment without allow testing flag', function () {
    configureImportPostgresConnection();

    $filePath = makeSqlImportPath('INSERT INTO "fire_incidents" ("id") VALUES (1) ON CONFLICT (id) DO NOTHING;');

    Process::fake();

    try {
        $this->artisan('db:import-sql', [
            '--file' => $filePath,
            '--force' => true,
        ])
            ->expectsOutputToContain('Refusing to import while APP_ENV is testing. Re-run with --allow-testing to override.')
            ->assertExitCode(1);

        Process::assertNothingRan();
    } finally {
        @unlink($filePath);
    }
});
