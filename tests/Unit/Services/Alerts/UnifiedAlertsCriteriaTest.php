<?php

use App\Services\Alerts\DTOs\UnifiedAlertsCriteria;
use App\Services\Alerts\DTOs\UnifiedAlertsCursor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

afterEach(function (): void {
    Carbon::setTestNow();
});

test('unified alerts criteria defaults', function () {
    $criteria = new UnifiedAlertsCriteria;

    expect($criteria->status)->toBe('all');
    expect($criteria->perPage)->toBe(50);
    expect($criteria->page)->toBeNull();
    expect($criteria->source)->toBeNull();
    expect($criteria->query)->toBeNull();
    expect($criteria->since)->toBeNull();
    expect($criteria->sinceCutoff)->toBeNull();
    expect($criteria->cursor)->toBeNull();
});

test('unified alerts criteria accepts valid values', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-02 12:00:00'));

    $cursor = UnifiedAlertsCursor::fromTuple(
        CarbonImmutable::parse('2026-02-02 11:00:00'),
        'fire:FIRE-0001',
    )->encode();

    $criteria = new UnifiedAlertsCriteria(
        status: 'active',
        perPage: 25,
        page: 2,
        source: 'fire',
        query: 'alarm',
        since: '1h',
        cursor: $cursor,
    );

    expect($criteria->status)->toBe('active');
    expect($criteria->perPage)->toBe(25);
    expect($criteria->page)->toBe(2);
    expect($criteria->source)->toBe('fire');
    expect($criteria->query)->toBe('alarm');
    expect($criteria->since)->toBe('1h');
    expect($criteria->sinceCutoff?->toDateTimeString())->toBe('2026-02-02 11:00:00');
    expect($criteria->cursor)->not->toBeNull();
    expect($criteria->cursor?->id)->toBe('fire:FIRE-0001');
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

test('unified alerts criteria trims query and normalizes empty values to null', function () {
    $criteria = new UnifiedAlertsCriteria(
        source: '  ',
        query: " \n\t ",
        since: '',
        cursor: '   ',
    );

    expect($criteria->source)->toBeNull();
    expect($criteria->query)->toBeNull();
    expect($criteria->since)->toBeNull();
    expect($criteria->cursor)->toBeNull();
});

test('unified alerts criteria rejects invalid source', function () {
    expect(fn () => new UnifiedAlertsCriteria(source: 'hazard'))
        ->toThrow(\InvalidArgumentException::class);
});

test('unified alerts criteria rejects invalid since', function () {
    expect(fn () => new UnifiedAlertsCriteria(since: '2h'))
        ->toThrow(\InvalidArgumentException::class);
});

test('unified alerts criteria rejects invalid cursor', function () {
    expect(fn () => new UnifiedAlertsCriteria(cursor: 'not-a-cursor'))
        ->toThrow(\InvalidArgumentException::class);
});
