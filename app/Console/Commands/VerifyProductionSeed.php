<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use RuntimeException;

/**
 * @deprecated 2026-02-24 Seeder export verification is superseded by the SQL workflow (`db:export-sql` / `db:import-sql`).
 */
class VerifyProductionSeed extends Command
{
    protected $signature = 'db:verify-production-seed
                            {--path= : Path to ProductionDataSeeder.php}';

    protected $description = 'Verify generated production seeder syntax and integrity';

    public function handle(): int
    {
        $mainPath = $this->resolveSeederPath();

        if (! is_file($mainPath)) {
            $this->error("Seeder file not found: {$mainPath}");

            return self::FAILURE;
        }

        try {
            $files = $this->resolveFilesToVerify($mainPath);
            $this->lintFiles($files);
            $this->verifyIntegrity($files);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Verification passed.');

        return self::SUCCESS;
    }

    private function resolveSeederPath(): string
    {
        $pathOption = $this->option('path');

        if (! is_string($pathOption) || trim($pathOption) === '') {
            return database_path('seeders/ProductionDataSeeder.php');
        }

        return $pathOption;
    }

    /**
     * @return list<string>
     */
    private function resolveFilesToVerify(string $mainPath): array
    {
        $mainContents = file_get_contents($mainPath);
        if ($mainContents === false) {
            throw new RuntimeException("Unable to read seeder file: {$mainPath}");
        }

        preg_match_all('/([A-Za-z_][A-Za-z0-9_]*_Part\d+)::class/', $mainContents, $matches);
        $partClasses = array_values(array_unique($matches[1] ?? []));

        if ($partClasses === []) {
            return [$mainPath];
        }

        $files = [$mainPath];
        foreach ($partClasses as $partClass) {
            $partPath = dirname($mainPath)."/{$partClass}.php";
            if (! is_file($partPath)) {
                throw new RuntimeException("Missing split seeder file referenced by main seeder: {$partPath}");
            }

            $files[] = $partPath;
        }

        return $files;
    }

    /**
     * @param  list<string>  $files
     */
    private function lintFiles(array $files): void
    {
        foreach ($files as $file) {
            $output = [];
            $exitCode = 0;

            exec('php -l '.escapeshellarg($file).' 2>&1', $output, $exitCode);

            if ($exitCode !== 0) {
                throw new RuntimeException(
                    "Syntax check failed for {$file}: ".implode("\n", $output)
                );
            }
        }
    }

    /**
     * @param  list<string>  $files
     */
    private function verifyIntegrity(array $files): void
    {
        $requiredTables = [
            'fire_incidents',
            'police_calls',
            'transit_alerts',
            'go_transit_alerts',
        ];

        $combinedContents = '';
        foreach ($files as $file) {
            $contents = file_get_contents($file);
            if ($contents === false) {
                throw new RuntimeException("Unable to read seeder file: {$file}");
            }

            $combinedContents .= $contents."\n";
        }

        $missingTables = [];
        foreach ($requiredTables as $table) {
            if (! str_contains($combinedContents, "DB::table('{$table}')->insertOrIgnore(")) {
                $missingTables[] = $table;
            }
        }

        if ($missingTables !== []) {
            throw new RuntimeException('Missing export blocks for tables: '.implode(', ', $missingTables));
        }

        if (! str_contains($combinedContents, "'created_at' =>")) {
            throw new RuntimeException("Missing 'created_at' values in exported seed data.");
        }

        if (! str_contains($combinedContents, "'updated_at' =>")) {
            throw new RuntimeException("Missing 'updated_at' values in exported seed data.");
        }
    }
}
