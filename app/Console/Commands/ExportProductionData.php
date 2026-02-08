<?php

namespace App\Console\Commands;

use App\Models\FireIncident;
use App\Models\GoTransitAlert;
use App\Models\PoliceCall;
use App\Models\TransitAlert;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Throwable;

class ExportProductionData extends Command
{
    protected $signature = 'db:export-to-seeder
                            {--path= : Output path for the generated seeder file}
                            {--chunk=500 : Number of records to export per chunk}';

    protected $description = 'Export production alert data into a Laravel seeder';

    public function handle(): int
    {
        $outputPath = $this->resolveOutputPath();
        $chunkSize = $this->resolveChunkSize();

        if (! $this->ensureOutputDirectoryExists($outputPath)) {
            return self::FAILURE;
        }

        $stream = fopen($outputPath, 'wb');
        if ($stream === false) {
            $this->error("Unable to open export file for writing: {$outputPath}");

            return self::FAILURE;
        }

        $totalExported = 0;

        try {
            $this->writeHeader($stream);

            foreach ($this->modelsToExport() as $modelClass) {
                $exported = $this->exportModelToSeeder($stream, $modelClass, $chunkSize);
                $totalExported += $exported;

                /** @var Model $model */
                $model = new $modelClass;
                $this->line("Exported {$exported} rows from {$model->getTable()}.");
            }

            $this->writeFooter($stream);
        } catch (Throwable $exception) {
            $this->error("Export failed: {$exception->getMessage()}");

            return self::FAILURE;
        } finally {
            fclose($stream);
        }

        $this->info("Seeder generated at {$outputPath} with {$totalExported} total rows.");

        return self::SUCCESS;
    }

    /**
     * @return list<class-string<Model>>
     */
    private function modelsToExport(): array
    {
        return [
            FireIncident::class,
            PoliceCall::class,
            TransitAlert::class,
            GoTransitAlert::class,
        ];
    }

    private function resolveOutputPath(): string
    {
        $optionPath = $this->option('path');

        if (! is_string($optionPath) || trim($optionPath) === '') {
            return database_path('seeders/ProductionDataSeeder.php');
        }

        return $optionPath;
    }

    private function resolveChunkSize(): int
    {
        $chunkOption = (int) $this->option('chunk');

        if ($chunkOption <= 0) {
            $this->warn('Invalid --chunk value provided. Falling back to 500.');

            return 500;
        }

        return $chunkOption;
    }

    private function ensureOutputDirectoryExists(string $outputPath): bool
    {
        $directory = dirname($outputPath);

        if (is_dir($directory)) {
            return true;
        }

        if (! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            $this->error("Unable to create output directory: {$directory}");

            return false;
        }

        return true;
    }

    /**
     * @param  resource  $stream
     * @param  class-string<Model>  $modelClass
     */
    private function exportModelToSeeder($stream, string $modelClass, int $chunkSize): int
    {
        /** @var Model $model */
        $model = new $modelClass;
        $table = $model->getTable();
        $keyName = $model->getKeyName();
        $exported = 0;

        fwrite($stream, "        // {$table}\n");

        $modelClass::query()
            ->orderBy($keyName)
            ->chunkById($chunkSize, function ($records) use ($stream, $table, &$exported): void {
                $rows = [];

                foreach ($records as $record) {
                    $rows[] = $record->getAttributes();
                }

                if ($rows === []) {
                    return;
                }

                fwrite(
                    $stream,
                    "        DB::table('{$table}')->insertOrIgnore(".var_export($rows, true).");\n\n"
                );

                $exported += count($rows);
            }, $keyName);

        return $exported;
    }

    /**
     * @param  resource  $stream
     */
    private function writeHeader($stream): void
    {
        fwrite($stream, <<<'PHP'
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProductionDataSeeder extends Seeder
{
    public function run(): void
    {

PHP);
    }

    /**
     * @param  resource  $stream
     */
    private function writeFooter($stream): void
    {
        fwrite($stream, <<<'PHP'
    }
}

PHP);
    }
}
