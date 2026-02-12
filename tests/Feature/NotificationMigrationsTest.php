<?php

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('notification tables exist with expected columns', function () {
    expect(Schema::hasTable('notification_preferences'))->toBeTrue();
    expect(Schema::hasColumns('notification_preferences', [
        'id',
        'user_id',
        'alert_type',
        'severity_threshold',
        'subscriptions',
        'digest_mode',
        'push_enabled',
        'created_at',
        'updated_at',
    ]))->toBeTrue();

    expect(Schema::hasTable('saved_places'))->toBeTrue();
    expect(Schema::hasColumns('saved_places', [
        'id',
        'user_id',
        'name',
        'lat',
        'long',
        'radius',
        'type',
        'created_at',
        'updated_at',
    ]))->toBeTrue();

    expect(Schema::hasTable('toronto_addresses'))->toBeTrue();
    expect(Schema::hasColumns('toronto_addresses', [
        'id',
        'street_num',
        'street_name',
        'lat',
        'long',
        'zip',
        'created_at',
        'updated_at',
    ]))->toBeTrue();

    expect(Schema::hasTable('toronto_pois'))->toBeTrue();
    expect(Schema::hasColumns('toronto_pois', [
        'id',
        'name',
        'category',
        'lat',
        'long',
        'created_at',
        'updated_at',
    ]))->toBeTrue();

    expect(Schema::hasTable('notification_logs'))->toBeTrue();
    expect(Schema::hasColumns('notification_logs', [
        'id',
        'user_id',
        'alert_id',
        'delivery_method',
        'status',
        'sent_at',
        'read_at',
        'dismissed_at',
        'metadata',
        'created_at',
        'updated_at',
    ]))->toBeTrue();
});

test('notification logs include high-read indexes', function () {
    expect(Schema::hasIndex('notification_logs', ['user_id']))->toBeFalse();
    expect(Schema::hasIndex('notification_logs', ['user_id', 'status', 'sent_at']))->toBeTrue();
    expect(Schema::hasIndex('notification_logs', ['status']))->toBeTrue();
    expect(Schema::hasIndex('notification_logs', ['sent_at']))->toBeTrue();
});

test('geofence drop migration backfills saved places before removing legacy column', function () {
    Schema::dropIfExists('saved_places');

    Schema::table('notification_preferences', function (Blueprint $table): void {
        $table->json('geofences')->nullable();
    });

    $user = User::factory()->create();

    DB::table('notification_preferences')->insert([
        'user_id' => $user->id,
        'alert_type' => 'all',
        'severity_threshold' => 'all',
        'geofences' => json_encode([
            ['name' => 'Downtown', 'lat' => 43.6535, 'lng' => -79.3839, 'radius_km' => 2],
            ['name' => null, 'lat' => 43.7615, 'lng' => -79.4111, 'radius_km' => 1.2],
            ['name' => 'Invalid', 'lat' => null, 'lng' => -79.5, 'radius_km' => 3],
        ], JSON_THROW_ON_ERROR),
        'subscriptions' => json_encode([], JSON_THROW_ON_ERROR),
        'digest_mode' => false,
        'push_enabled' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration = require database_path('migrations/2026_02_12_000001_drop_geofences_from_notification_preferences_table.php');
    $migration->up();

    expect(Schema::hasTable('saved_places'))->toBeTrue();
    expect(Schema::hasColumn('notification_preferences', 'geofences'))->toBeFalse();

    $savedPlaces = DB::table('saved_places')
        ->where('user_id', $user->id)
        ->orderBy('id')
        ->get(['name', 'lat', 'long', 'radius', 'type']);

    expect($savedPlaces)->toHaveCount(2);
    expect($savedPlaces[0]->name)->toBe('Downtown');
    expect((int) $savedPlaces[0]->radius)->toBe(2000);
    expect($savedPlaces[0]->type)->toBe('legacy_geofence');

    expect($savedPlaces[1]->name)->toBe('Saved Zone');
    expect((int) $savedPlaces[1]->radius)->toBe(1200);
    expect($savedPlaces[1]->type)->toBe('legacy_geofence');
});

test('subscription rename migration copies subscribed routes into subscriptions', function () {
    $user = User::factory()->create();

    Schema::table('notification_preferences', function (Blueprint $table): void {
        $table->json('subscribed_routes')->nullable();
    });

    DB::table('notification_preferences')->insert([
        'user_id' => $user->id,
        'alert_type' => 'transit',
        'severity_threshold' => 'minor',
        'subscribed_routes' => json_encode(['route:501', 'station:union'], JSON_THROW_ON_ERROR),
        'digest_mode' => false,
        'push_enabled' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Schema::table('notification_preferences', function (Blueprint $table): void {
        $table->dropColumn('subscriptions');
    });

    $migration = require database_path('migrations/2026_02_12_000005_rename_subscribed_routes_to_subscriptions_in_notification_preferences_table.php');
    $migration->up();

    expect(Schema::hasColumn('notification_preferences', 'subscribed_routes'))->toBeFalse();
    expect(Schema::hasColumn('notification_preferences', 'subscriptions'))->toBeTrue();

    $subscriptions = DB::table('notification_preferences')
        ->where('user_id', $user->id)
        ->value('subscriptions');

    expect($subscriptions)->toBe(json_encode(['route:501', 'station:union'], JSON_THROW_ON_ERROR));
});
