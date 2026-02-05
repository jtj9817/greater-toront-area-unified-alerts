<?php

use App\Enums\AlertStatus;

test('alert status exposes ordered values', function () {
    expect(AlertStatus::values())->toBe(['all', 'active', 'cleared']);
});

test('alert status normalizes null to all', function () {
    expect(AlertStatus::normalize(null))->toBe('all');
});

test('alert status normalizes valid values', function (string $value) {
    expect(AlertStatus::normalize($value))->toBe($value);
})->with([
    'all' => ['all'],
    'active' => ['active'],
    'cleared' => ['cleared'],
]);

test('alert status throws for invalid values', function () {
    expect(fn () => AlertStatus::normalize('invalid'))
        ->toThrow(\InvalidArgumentException::class);
});
