<?php

use App\Models\FireIncident;
use App\Models\GoTransitAlert;
use App\Models\PoliceCall;
use App\Models\TransitAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeVerifySeederTempDirectory(): string
{
    $directory = sys_get_temp_dir().'/verify-production-seed-'.bin2hex(random_bytes(6));

    if (! mkdir($directory, 0755, true) && ! is_dir($directory)) {
        throw new RuntimeException("Unable to create temporary verification directory: {$directory}");
    }

    return $directory;
}

function deleteVerifySeederTempDirectory(string $directory): void
{
    if (! is_dir($directory)) {
        return;
    }

    $paths = glob($directory.'/*');
    if (is_array($paths)) {
        foreach ($paths as $path) {
            if (is_dir($path)) {
                deleteVerifySeederTempDirectory($path);
            } else {
                @unlink($path);
            }
        }
    }

    @rmdir($directory);
}

test('it verifies generated split seeders successfully', function () {
    FireIncident::factory()->count(30)->create(['units_dispatched' => str_repeat('P100, ', 20)]);
    PoliceCall::factory()->count(30)->create();
    TransitAlert::factory()->count(30)->create();
    GoTransitAlert::factory()->count(30)->create();

    $directory = makeVerifySeederTempDirectory();
    $outputPath = $directory.'/ProductionDataSeeder.php';

    try {
        $this->artisan('db:export-to-seeder', [
            '--path' => $outputPath,
            '--chunk' => 10,
            '--max-bytes' => 5000,
        ])->assertExitCode(0);

        $this->artisan('db:verify-production-seed', [
            '--path' => $outputPath,
        ])->assertExitCode(0)
            ->expectsOutputToContain('Verification passed');
    } finally {
        deleteVerifySeederTempDirectory($directory);
    }
});

test('it fails verification when seeder has a syntax error', function () {
    $directory = makeVerifySeederTempDirectory();
    $outputPath = $directory.'/ProductionDataSeeder.php';

    try {
        file_put_contents($outputPath, "<?php\nclass BrokenSeeder {\n public function run() {\n");

        $this->artisan('db:verify-production-seed', [
            '--path' => $outputPath,
        ])->assertExitCode(1)
            ->expectsOutputToContain('Syntax check failed');
    } finally {
        deleteVerifySeederTempDirectory($directory);
    }
});

test('it fails verification when required table exports are missing', function () {
    $directory = makeVerifySeederTempDirectory();
    $outputPath = $directory.'/ProductionDataSeeder.php';

    try {
        file_put_contents($outputPath, <<<'PHP'
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProductionDataSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('fire_incidents')->insertOrIgnore([
            [
                'id' => 1,
                'event_num' => 'F26000001',
                'created_at' => '2026-02-07 00:00:00',
                'updated_at' => '2026-02-07 00:00:00',
            ],
        ]);
    }
}
PHP);

        $this->artisan('db:verify-production-seed', [
            '--path' => $outputPath,
        ])->assertExitCode(1)
            ->expectsOutputToContain('Missing export blocks for tables');
    } finally {
        deleteVerifySeederTempDirectory($directory);
    }
});
