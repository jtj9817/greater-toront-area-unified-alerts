<?php

use App\Enums\AlertSource;

test('alert source exposes ordered values', function () {
    expect(AlertSource::values())->toBe(['fire', 'police', 'transit']);
});

test('alert source validates expected values', function (string $value) {
    expect(AlertSource::isValid($value))->toBeTrue();
})->with([
    'fire' => ['fire'],
    'police' => ['police'],
    'transit' => ['transit'],
]);

test('alert source rejects unexpected values', function (mixed $value) {
    expect(AlertSource::isValid($value))->toBeFalse();
})->with([
    'null' => [null],
    'empty' => [''],
    'unknown' => ['unknown'],
]);
