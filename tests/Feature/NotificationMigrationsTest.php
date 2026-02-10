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
        'geofences',
        'subscribed_routes',
        'digest_mode',
        'push_enabled',
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
    expect(Schema::hasIndex('notification_logs', ['user_id']))->toBeTrue();
    expect(Schema::hasIndex('notification_logs', ['status']))->toBeTrue();
    expect(Schema::hasIndex('notification_logs', ['sent_at']))->toBeTrue();
});
