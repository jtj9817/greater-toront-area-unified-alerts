<?php

use App\Services\Alerts\UnifiedAlertsQuery;

uses(Tests\TestCase::class);

test('unified alerts query meta decoder returns arrays for arrays', function () {
    $query = app(UnifiedAlertsQuery::class);

    $reflection = new ReflectionClass($query);
    $decodeMeta = $reflection->getMethod('decodeMeta');
    $decodeMeta->setAccessible(true);

    $value = ['a' => 1, 'b' => 'two'];
    expect($decodeMeta->invoke($query, $value))->toBe($value);
});

test('unified alerts query meta decoder returns empty array for invalid JSON', function () {
    $query = app(UnifiedAlertsQuery::class);

    $reflection = new ReflectionClass($query);
    $decodeMeta = $reflection->getMethod('decodeMeta');
    $decodeMeta->setAccessible(true);

    expect($decodeMeta->invoke($query, '{"invalid'))->toBe([]);
});

test('unified alerts query meta decoder returns empty array for empty string and null', function () {
    $query = app(UnifiedAlertsQuery::class);

    $reflection = new ReflectionClass($query);
    $decodeMeta = $reflection->getMethod('decodeMeta');
    $decodeMeta->setAccessible(true);

    expect($decodeMeta->invoke($query, ''))->toBe([]);
    expect($decodeMeta->invoke($query, null))->toBe([]);
});
