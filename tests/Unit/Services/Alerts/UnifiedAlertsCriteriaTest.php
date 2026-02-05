<?php

use App\Services\Alerts\DTOs\UnifiedAlertsCriteria;

test('unified alerts criteria defaults', function () {
    $criteria = new UnifiedAlertsCriteria;

    expect($criteria->status)->toBe('all');
    expect($criteria->perPage)->toBe(50);
    expect($criteria->page)->toBeNull();
});

test('unified alerts criteria accepts valid values', function () {
    $criteria = new UnifiedAlertsCriteria(status: 'active', perPage: 25, page: 2);

    expect($criteria->status)->toBe('active');
    expect($criteria->perPage)->toBe(25);
    expect($criteria->page)->toBe(2);
});

test('unified alerts criteria enforces per-page bounds', function (int $perPage) {
    expect(fn () => new UnifiedAlertsCriteria(perPage: $perPage))
        ->toThrow(\InvalidArgumentException::class);
})->with([
    'too small' => [0],
    'too large' => [UnifiedAlertsCriteria::MAX_PER_PAGE + 1],
]);

test('unified alerts criteria enforces page bounds', function () {
    expect(fn () => new UnifiedAlertsCriteria(page: 0))
        ->toThrow(\InvalidArgumentException::class);
});

test('unified alerts criteria rejects invalid status', function () {
    expect(fn () => new UnifiedAlertsCriteria(status: 'invalid'))
        ->toThrow(\InvalidArgumentException::class);
});
