<?php

use App\Models\MiwayAlert;
use Illuminate\Support\Facades\Artisan;

require __DIR__.'/../../vendor/autoload.php';

$app = require_once __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "\n=============================================\n";
echo "MiWay Phase 3: Fetch Command Verification\n";
echo "=============================================\n\n";

if (config('app.env') !== 'testing' || config('database.default') !== 'sqlite') {
    echo "Note: Not running in 'testing' environment.\n";
}

try {
    echo "1. Pre-fetch state:\n";
    $count = MiwayAlert::count();
    echo "  Total MiWay alerts in DB: {$count}\n\n";

    echo "2. Running miway:fetch-alerts...\n";
    Artisan::call('miway:fetch-alerts');
    echo "  Output:\n  ".str_replace("\n", "\n  ", trim(Artisan::output()))."\n\n";

    echo "3. Post-fetch state:\n";
    $count = MiwayAlert::count();
    echo "  Total MiWay alerts in DB: {$count}\n";
    $activeCount = MiwayAlert::active()->count();
    echo "  Active MiWay alerts: {$activeCount}\n\n";

    if ($activeCount > 0) {
        echo "4. Running miway:fetch-alerts again (Testing 304 fallback)...\n";
        Artisan::call('miway:fetch-alerts');
        echo "  Output:\n  ".str_replace("\n", "\n  ", trim(Artisan::output()))."\n\n";
    }

} catch (Throwable $e) {
    echo '❌ ERROR: '.$e->getMessage()."\n";
    exit(1);
}

echo "Verification Complete. Ready for Phase 4.\n";
echo "=============================================\n";
