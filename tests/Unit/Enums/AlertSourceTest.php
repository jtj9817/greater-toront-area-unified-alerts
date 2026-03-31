<?php

use App\Enums\AlertSource;

test('alert source exposes ordered values', function () {
    expect(AlertSource::values())->toBe(['fire', 'police', 'transit', 'go_transit', 'miway']);
});

test('alert source validates expected values', function (string $value) {
    expect(AlertSource::isValid($value))->toBeTrue();
})->with([
    'fire' => ['fire'],
    'police' => ['police'],
    'transit' => ['transit'],
    'go_transit' => ['go_transit'],
    'miway' => ['miway'],
]);

test('alert source rejects unexpected values', function (mixed $value) {
    expect(AlertSource::isValid($value))->toBeFalse();
})->with([
    'null' => [null],
    'empty' => [''],
    'unknown' => ['unknown'],
]);
