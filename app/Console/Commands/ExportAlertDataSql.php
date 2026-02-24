<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class ExportAlertDataSql extends Command
{
    private const DEFAULT_CHUNK_SIZE = 500;

    /**
     * @var list<string>
     */
    private const DEFAULT_TABLES = [
        'fire_incidents',
        'police_calls',
        'transit_alerts',
        'go_transit_alerts',
    ];

    protected $signature = 'db:export-sql
                            {--output= : Output path for the SQL export}
                            {--tables= : Comma-separated list of tables to export}
                            {--chunk=500 : Number of rows per VALUES batch}
                            {--compress : Compress output as .sql.gz}
                            {--no-header : Omit header statements}';

    protected $description = 'Export alert data tables to Postgres-compatible SQL INSERT statements';

    public function handle(): int
    {
        try {
            $tables = $this->resolveTables();
            $chunkSize = $this->resolveChunkSize();
            $outputPath = $this->resolveOutputPath();
            $compress = (bool) $this->option('compress');

            $this->ensureOutputDirectoryExists($outputPath);

            $writer = $this->openWriter($outputPath, $compress);

            try {
                if (! $this->option('no-header')) {
                    $this->writeHeader($writer['write']);
                }

                $totalRows = 0;

                foreach ($tables as $table) {
                    $exported = $this->exportTable(
                        table: $table,
                        chunkSize: $chunkSize,
                        write: $writer['write'],
                    );

                    $totalRows += $exported;
                    $this->line("Exported {$exported} rows from {$table}.");
                }
            } finally {
                ($writer['close'])();
            }
        } catch (Throwable $exception) {
            $this->error("Export failed: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->info("SQL export generated at {$outputPath} with {$totalRows} total rows.");

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function resolveTables(): array
    {
        $option = $this->option('tables');

        if (! is_string($option) || trim($option) === '') {
            return self::DEFAULT_TABLES;
        }

        $tables = array_values(array_unique(array_filter(array_map(
            static fn (string $table): string => trim($table),
            explode(',', $option),
        ))));

        if ($tables === []) {
            throw new RuntimeException('No tables were provided for export.');
        }

        $invalidTables = array_values(array_diff($tables, self::DEFAULT_TABLES));
        if ($invalidTables !== []) {
            throw new RuntimeException(
                'Unsupported table(s): '.implode(', ', $invalidTables).'. Allowed values: '.implode(', ', self::DEFAULT_TABLES).'.'
            );
        }

        return $tables;
    }

    private function resolveChunkSize(): int
    {
        $chunkOption = (int) $this->option('chunk');

        if ($chunkOption <= 0) {
            $this->warn('Invalid --chunk value provided. Falling back to 500.');

            return self::DEFAULT_CHUNK_SIZE;
        }

        return $chunkOption;
    }

    private function resolveOutputPath(): string
    {
        $option = $this->option('output');
        $path = (is_string($option) && trim($option) !== '')
            ? trim($option)
            : storage_path('app/alert-export.sql');

        if ($this->option('compress') && ! str_ends_with($path, '.gz')) {
            $path .= '.gz';
        }

        return $path;
    }

    private function ensureOutputDirectoryExists(string $outputPath): void
    {
        $directory = dirname($outputPath);

        if (is_dir($directory)) {
            return;
        }

        if (! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create output directory: {$directory}");
        }
    }

    /**
     * @return array{
     *   write: \Closure(string): void,
     *   close: \Closure(): void
     * }
     */
    private function openWriter(string $outputPath, bool $compress): array
    {
        if ($compress) {
            $stream = gzopen($outputPath, 'wb9');

            if ($stream === false) {
                throw new RuntimeException("Unable to open compressed output file: {$outputPath}");
            }

            return [
                'write' => static function (string $content) use ($stream): void {
                    if (gzwrite($stream, $content) === false) {
                        throw new RuntimeException('Failed to write compressed SQL export output.');
                    }
                },
                'close' => static function () use ($stream): void {
                    gzclose($stream);
                },
            ];
        }

        $stream = fopen($outputPath, 'wb');

        if ($stream === false) {
            throw new RuntimeException("Unable to open output file for writing: {$outputPath}");
        }

        return [
            'write' => static function (string $content) use ($stream): void {
                if (fwrite($stream, $content) === false) {
                    throw new RuntimeException('Failed to write SQL export output.');
                }
            },
            'close' => static function () use ($stream): void {
                fclose($stream);
            },
        ];
    }

    /**
     * @param  \Closure(string): void  $write
     */
    private function writeHeader(\Closure $write): void
    {
        $write("-- GTA Alerts SQL Export\n");
        $write("-- Postgres-compatible idempotent data load\n");
        $write("SET client_encoding = 'UTF8';\n");
        $write("SET TIME ZONE 'UTC';\n\n");
    }

    /**
     * @param  \Closure(string): void  $write
     */
    private function exportTable(string $table, int $chunkSize, \Closure $write): int
    {
        if (! Schema::hasTable($table)) {
            throw new RuntimeException("Table does not exist: {$table}");
        }

        $columns = Schema::getColumnListing($table);
        if ($columns === []) {
            return 0;
        }

        if (! in_array('id', $columns, true)) {
            throw new RuntimeException("Table {$table} is missing required primary key column: id");
        }

        $quotedTable = $this->quoteIdentifier($table);
        $quotedColumns = implode(', ', array_map(
            fn (string $column): string => $this->quoteIdentifier($column),
            $columns
        ));
        $booleanColumns = $this->resolveBooleanColumns($table, $columns);

        $exported = 0;

        DB::table($table)
            ->orderBy('id')
            ->chunkById($chunkSize, function ($rows) use ($columns, $quotedColumns, $quotedTable, $booleanColumns, &$exported, $write): void {
                if ($rows->isEmpty()) {
                    return;
                }

                $valueRows = [];
                foreach ($rows as $row) {
                    $attributes = (array) $row;
                    $literals = [];

                    foreach ($columns as $column) {
                        $literals[] = $this->toSqlLiteral(
                            value: $attributes[$column] ?? null,
                            treatAsBoolean: isset($booleanColumns[$column]),
                        );
                    }

                    $valueRows[] = '('.implode(', ', $literals).')';
                }

                $statement = "INSERT INTO {$quotedTable} ({$quotedColumns}) VALUES\n";
                $statement .= implode(",\n", $valueRows);
                $statement .= "\nON CONFLICT (id) DO NOTHING;\n\n";

                $write($statement);
                $exported += count($valueRows);
            }, 'id');

        $write($this->buildSequenceResetStatement($table));

        return $exported;
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    private function buildSequenceResetStatement(string $table): string
    {
        $tableLiteral = str_replace("'", "''", $table);
        $quotedTable = $this->quoteIdentifier($table);

        return "SELECT setval(pg_get_serial_sequence('{$tableLiteral}', 'id'), COALESCE(MAX(\"id\"), 1), MAX(\"id\") IS NOT NULL) FROM {$quotedTable};\n\n";
    }

    /**
     * @param  list<string>  $columns
     * @return array<string, true>
     */
    private function resolveBooleanColumns(string $table, array $columns): array
    {
        $booleanColumns = [];

        foreach ($columns as $column) {
            $columnType = strtolower(Schema::getColumnType($table, $column));

            if (in_array($columnType, ['bool', 'boolean'], true) || $column === 'is_active') {
                $booleanColumns[$column] = true;
            }
        }

        return $booleanColumns;
    }

    private function toSqlLiteral(mixed $value, bool $treatAsBoolean = false): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if ($treatAsBoolean) {
            return $this->toBooleanLiteral($value);
        }

        if (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        $stringValue = str_replace("'", "''", (string) $value);

        return "'{$stringValue}'";
    }

    private function toBooleanLiteral(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value === 0 ? 'FALSE' : 'TRUE';
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            if (in_array($normalized, ['0', 'f', 'false', 'n', 'no', 'off'], true)) {
                return 'FALSE';
            }

            if (in_array($normalized, ['1', 't', 'true', 'y', 'yes', 'on'], true)) {
                return 'TRUE';
            }
        }

        return (bool) $value ? 'TRUE' : 'FALSE';
    }
}
