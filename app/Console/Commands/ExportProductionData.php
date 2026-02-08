<?php

namespace App\Console\Commands;

use App\Models\FireIncident;
use App\Models\GoTransitAlert;
use App\Models\PoliceCall;
use App\Models\TransitAlert;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Throwable;

class ExportProductionData extends Command
{
    private const DEFAULT_CHUNK_SIZE = 500;

    private const DEFAULT_MAX_BYTES = 10485760; // 10 MB

    private const MAIN_SEEDER_CLASS = 'ProductionDataSeeder';

    protected $signature = 'db:export-to-seeder
                            {--path= : Output path for the generated seeder file}
                            {--chunk=500 : Number of records to export per chunk}
                            {--max-bytes=10485760 : Max bytes per seeder file before splitting}';

    protected $description = 'Export production alert data into a Laravel seeder';

    /**
     * @var array{
     *   stream: resource,
     *   path: string,
     *   class: string,
     *   has_blocks: bool
     * }|null
     */
    private ?array $activeSeederFile = null;

    /**
     * @var list<string>
     */
    private array $partPaths = [];

    private bool $splitMode = false;

    private int $maxBytes = self::DEFAULT_MAX_BYTES;

    private string $mainSeederPath = '';

    private string $mainSeederClass = '';

    public function handle(): int
    {
        $outputPath = $this->resolveOutputPath();
        $chunkSize = $this->resolveChunkSize();
        $maxBytes = $this->resolveMaxBytes();

        if (! $this->ensureOutputDirectoryExists($outputPath)) {
            return self::FAILURE;
        }

        $this->mainSeederPath = $outputPath;
        $this->mainSeederClass = self::MAIN_SEEDER_CLASS;
        $this->maxBytes = $maxBytes;
        $this->splitMode = false;
        $this->partPaths = [];
        $totalExported = 0;

        try {
            $this->openSeederFile($this->mainSeederPath, $this->mainSeederClass);

            foreach ($this->modelsToExport() as $modelClass) {
                $exported = $this->exportModelToSeeder($modelClass, $chunkSize);
                $totalExported += $exported;

                /** @var Model $model */
                $model = new $modelClass;
                $this->line("Exported {$exported} rows from {$model->getTable()}.");
            }

            $closedPath = $this->closeSeederFile();

            if ($this->splitMode) {
                $this->partPaths[] = $closedPath;
                $this->writeSplitMainSeeder();
            }
        } catch (Throwable $exception) {
            $this->error("Export failed: {$exception->getMessage()}");

            $this->closeSeederFileQuietly();

            return self::FAILURE;
        }

        if ($this->splitMode) {
            $this->info('Seeder split into '.count($this->partPaths).' part files.');
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

            return self::DEFAULT_CHUNK_SIZE;
        }

        return $chunkOption;
    }

    private function resolveMaxBytes(): int
    {
        $maxBytesOption = (int) $this->option('max-bytes');

        if ($maxBytesOption <= 0) {
            $this->warn('Invalid --max-bytes value provided. Falling back to 10485760.');

            return self::DEFAULT_MAX_BYTES;
        }

        return $maxBytesOption;
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
     * @param  class-string<Model>  $modelClass
     */
    private function exportModelToSeeder(string $modelClass, int $chunkSize): int
    {
        /** @var Model $model */
        $model = new $modelClass;
        $table = $model->getTable();
        $keyName = $model->getKeyName();
        $exported = 0;
        $writeTableComment = true;

        $modelClass::query()
            ->orderBy($keyName)
            ->chunkById($chunkSize, function ($records) use ($table, &$exported, &$writeTableComment): void {
                $rows = [];

                foreach ($records as $record) {
                    $rows[] = $record->getAttributes();
                }

                if ($rows === []) {
                    return;
                }

                $statement = $this->makeInsertStatement($table, $rows, $writeTableComment);
                $this->writeBlock($statement);
                $writeTableComment = false;

                $exported += count($rows);
            }, $keyName);

        return $exported;
    }

    private function makeInsertStatement(string $table, array $rows, bool $includeTableComment): string
    {
        $prefix = $includeTableComment ? "        // {$table}\n" : '';

        return $prefix."        DB::table('{$table}')->insertOrIgnore(".var_export($rows, true).");\n\n";
    }

    private function openSeederFile(string $path, string $className): void
    {
        $stream = fopen($path, 'wb');
        if ($stream === false) {
            throw new RuntimeException("Unable to open export file for writing: {$path}");
        }

        fwrite($stream, $this->buildSeederHeader($className));

        $this->activeSeederFile = [
            'stream' => $stream,
            'path' => $path,
            'class' => $className,
            'has_blocks' => false,
        ];
    }

    private function closeSeederFile(): string
    {
        if ($this->activeSeederFile === null) {
            throw new RuntimeException('No active seeder file is open for writing.');
        }

        fwrite($this->activeSeederFile['stream'], $this->buildSeederFooter());
        fclose($this->activeSeederFile['stream']);

        $closedPath = $this->activeSeederFile['path'];
        $this->activeSeederFile = null;

        return $closedPath;
    }

    private function closeSeederFileQuietly(): void
    {
        if ($this->activeSeederFile === null) {
            return;
        }

        @fclose($this->activeSeederFile['stream']);
        $this->activeSeederFile = null;
    }

    private function writeBlock(string $block): void
    {
        if ($this->activeSeederFile === null) {
            throw new RuntimeException('Cannot write export block without an active seeder file.');
        }

        if ($this->shouldRotate($block)) {
            $this->rotateSeederFile();
        }

        fwrite($this->activeSeederFile['stream'], $block);
        $this->activeSeederFile['has_blocks'] = true;
    }

    private function shouldRotate(string $block): bool
    {
        if ($this->activeSeederFile === null) {
            throw new RuntimeException('Rotation check failed because no active seeder file exists.');
        }

        if (! $this->activeSeederFile['has_blocks']) {
            return false;
        }

        $currentSize = ftell($this->activeSeederFile['stream']);
        if (! is_int($currentSize)) {
            throw new RuntimeException('Unable to determine current seeder file size during export.');
        }

        return ($currentSize + strlen($block) + strlen($this->buildSeederFooter())) > $this->maxBytes;
    }

    private function rotateSeederFile(): void
    {
        if ($this->activeSeederFile === null) {
            throw new RuntimeException('No active seeder file is open to rotate.');
        }

        $closedPath = $this->closeSeederFile();

        if (! $this->splitMode) {
            $this->splitMode = true;

            $partOnePath = $this->partSeederPath(1);
            $partOneClass = $this->partSeederClass(1);

            if (! rename($closedPath, $partOnePath)) {
                throw new RuntimeException("Unable to split export: failed to rename {$closedPath} to {$partOnePath}.");
            }

            $this->replaceSeederClassName($partOnePath, $this->mainSeederClass, $partOneClass);
            $this->partPaths[] = $partOnePath;
            $this->warn("Export exceeded {$this->maxBytes} bytes. Splitting output into part seeders.");
        } else {
            $this->partPaths[] = $closedPath;
        }

        $nextPartIndex = count($this->partPaths) + 1;
        $this->openSeederFile($this->partSeederPath($nextPartIndex), $this->partSeederClass($nextPartIndex));
    }

    private function replaceSeederClassName(string $path, string $fromClass, string $toClass): void
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Unable to read seeder file for class update: {$path}");
        }

        $updated = str_replace(
            "class {$fromClass} extends Seeder",
            "class {$toClass} extends Seeder",
            $contents,
            $replaceCount
        );

        if ($replaceCount !== 1) {
            throw new RuntimeException("Unable to update class declaration in {$path} for split export.");
        }

        if (file_put_contents($path, $updated) === false) {
            throw new RuntimeException("Unable to write updated split seeder class to {$path}");
        }
    }

    private function partSeederClass(int $part): string
    {
        return "{$this->mainSeederClass}_Part{$part}";
    }

    private function partSeederPath(int $part): string
    {
        return dirname($this->mainSeederPath).'/'.$this->partSeederClass($part).'.php';
    }

    private function writeSplitMainSeeder(): void
    {
        $calls = [];
        foreach ($this->partPaths as $partPath) {
            $calls[] = '            '.pathinfo($partPath, PATHINFO_FILENAME).'::class';
        }

        $contents = <<<'PHP'
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class %s extends Seeder
{
    public function run(): void
    {
        $this->call([
%s
        ]);
    }
}

PHP;

        $payload = sprintf($contents, $this->mainSeederClass, implode(",\n", $calls));

        if (file_put_contents($this->mainSeederPath, $payload) === false) {
            throw new RuntimeException("Unable to write split main seeder file: {$this->mainSeederPath}");
        }
    }

    private function buildSeederHeader(string $className): string
    {
        return sprintf(<<<'PHP'
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class %s extends Seeder
{
    public function run(): void
    {

PHP, $className);
    }

    private function buildSeederFooter(): string
    {
        return <<<'PHP'
    }
}

PHP;
    }
}
