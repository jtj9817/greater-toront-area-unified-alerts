<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('notification tables exist with expected columns', function () {
    expect(Schema::hasTable('notification_preferences'))->toBeTrue();
    expect(Schema::hasColumns('notification_preferences', [
        'id',
        'user_id',
        'alert_type',
        'severity_threshold',
        'subscribed_routes',
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
