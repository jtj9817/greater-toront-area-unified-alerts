<?php

use App\Rules\UnifiedAlertsCursorRule;
use App\Services\Alerts\DTOs\UnifiedAlertsCursor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;

uses(Tests\TestCase::class);

test('unified alerts cursor rule accepts null', function () {
    $validator = Validator::make([
        'cursor' => null,
    ], [
        'cursor' => [new UnifiedAlertsCursorRule],
    ]);

    expect($validator->passes())->toBeTrue();
});

test('unified alerts cursor rule rejects non-string values', function () {
    $validator = Validator::make([
        'cursor' => 42,
    ], [
        'cursor' => [new UnifiedAlertsCursorRule],
    ]);

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->first('cursor'))->toBe('The cursor must be a string.');
});

test('unified alerts cursor rule accepts blank string', function () {
    $validator = Validator::make([
        'cursor' => '   ',
    ], [
        'cursor' => [new UnifiedAlertsCursorRule],
    ]);

    expect($validator->passes())->toBeTrue();
});

test('unified alerts cursor rule rejects invalid encoded cursor', function () {
    $validator = Validator::make([
        'cursor' => 'not-a-valid-cursor',
    ], [
        'cursor' => [new UnifiedAlertsCursorRule],
    ]);

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->first('cursor'))->toBe('The cursor is invalid.');
});

test('unified alerts cursor rule accepts valid encoded cursor', function () {
    $cursor = UnifiedAlertsCursor::fromTuple(
        CarbonImmutable::parse('2026-02-24 12:00:00'),
        'transit:api:61748',
    )->encode();

    $validator = Validator::make([
        'cursor' => $cursor,
    ], [
        'cursor' => [new UnifiedAlertsCursorRule],
    ]);

    expect($validator->passes())->toBeTrue();
});

test('unified alerts cursor rule accepts valid encoded cursor with surrounding whitespace', function () {
    $cursor = UnifiedAlertsCursor::fromTuple(
        CarbonImmutable::parse('2026-02-24 12:00:00'),
        'transit:api:61748',
    )->encode();

    $validator = Validator::make([
        'cursor' => "  {$cursor}  ",
    ], [
        'cursor' => [new UnifiedAlertsCursorRule],
    ]);

    expect($validator->passes())->toBeTrue();
});
