<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('saved_places')) {
            Schema::create('saved_places', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('name', 120);
                $table->decimal('lat', 10, 7);
                $table->decimal('long', 10, 7);
                $table->unsignedInteger('radius')->default(500);
                $table->string('type', 32)->default('address');
                $table->timestamps();

                $table->index('user_id');
                $table->index(['user_id', 'type']);
            });
        }

        if (! Schema::hasColumn('notification_preferences', 'geofences')) {
            return;
        }

        $now = now();
        $recordsToInsert = [];

        $preferences = DB::table('notification_preferences')
            ->select('user_id', 'geofences')
            ->whereNotNull('geofences')
            ->get();

        foreach ($preferences as $preference) {
            $decodedGeofences = json_decode((string) $preference->geofences, true);

            if (! is_array($decodedGeofences)) {
                continue;
            }

            foreach ($decodedGeofences as $geofence) {
                if (! is_array($geofence)) {
                    continue;
                }

                $lat = $geofence['lat'] ?? null;
                $lng = $geofence['lng'] ?? null;

                if (! is_numeric($lat) || ! is_numeric($lng)) {
                    continue;
                }

                $radiusKm = $geofence['radius_km'] ?? 0;
                $radiusMeters = max(100, (int) round((float) $radiusKm * 1000));
                $name = trim((string) ($geofence['name'] ?? 'Saved Zone'));

                $recordsToInsert[] = [
                    'user_id' => (int) $preference->user_id,
                    'name' => $name !== '' ? $name : 'Saved Zone',
                    'lat' => (float) $lat,
                    'long' => (float) $lng,
                    'radius' => $radiusMeters,
                    'type' => 'legacy_geofence',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($recordsToInsert !== []) {
            DB::table('saved_places')->insert($recordsToInsert);
        }

        Schema::table('notification_preferences', function (Blueprint $table): void {
            $table->dropColumn('geofences');
        });
    }

    public function down(): void
    {
        Schema::table('notification_preferences', function (Blueprint $table): void {
            $table->json('geofences')->default('[]');
        });
    }
};
