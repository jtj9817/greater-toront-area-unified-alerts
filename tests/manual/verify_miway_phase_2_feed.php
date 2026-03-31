<?php

use App\Services\MiwayGtfsRtAlertsFeedService;
use Carbon\CarbonInterface;

require __DIR__.'/../../vendor/autoload.php';

$app = require_once __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "\n=============================================\n";
echo "MiWay Phase 2: GTFS-RT Feed Service Verification\n";
echo "=============================================\n\n";

if (config('app.env') !== 'testing' || config('database.default') !== 'sqlite') {
    // Just a warning, not destructive
    echo "Note: Not running in 'testing' environment.\n";
}

try {
    $service = app(MiwayGtfsRtAlertsFeedService::class);
    
    echo "1. Fetching MiWay GTFS-RT feed...\n";
    $result = $service->fetch();
    
    echo "✅ Fetch successful!\n";
    
    $updatedAt = $result['updated_at'] ?? null;
    if ($updatedAt instanceof CarbonInterface) {
        echo "✅ feed_updated_at: " . $updatedAt->toIso8601String() . "\n";
    } else {
        echo "❌ feed_updated_at is missing or invalid type\n";
    }
    
    $alerts = $result['alerts'] ?? [];
    echo "✅ Found " . count($alerts) . " active alerts in feed.\n\n";
    
    if (count($alerts) > 0) {
        echo "Sample Alert [0]:\n";
        $alert = $alerts[0];
        
        $keys = [
            'external_id', 'header_text', 'description_text', 
            'cause', 'effect', 'starts_at', 'ends_at', 'url', 'detour_pdf_url'
        ];
        
        foreach ($keys as $key) {
            $val = $alert[$key] ?? 'null';
            if ($val instanceof CarbonInterface) {
                $val = $val->toIso8601String();
            }
            echo sprintf("  %-16s : %s\n", $key, (string)$val);
        }
    }
    
    echo "\n2. Testing 304 Not Modified path...\n";
    // We can't guarantee a 304 against the real API without the real ETag, but we can fake it.
    // Actually, let's just use Http::fake() for a quick test if it works.
    Illuminate\Support\Facades\Http::fake([
        '*' => Illuminate\Support\Facades\Http::response('', 304)
    ]);
    
    $result304 = $service->fetch('dummy-etag', 'dummy-date');
    if (($result304['not_modified'] ?? false) === true) {
        echo "✅ 304 Not Modified short-circuit works.\n";
    } else {
        echo "❌ 304 Not Modified failed.\n";
    }

} catch (Throwable $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\nVerification Complete. Ready for Phase 3.\n";
echo "=============================================\n";
