<?php

use App\Services\Alerts\DTOs\AlertId;

test('alert id builds from parts', function () {
    $alertId = AlertId::fromParts('fire', 'F123');

    expect($alertId->source)->toBe('fire');
    expect($alertId->externalId)->toBe('F123');
    expect($alertId->value())->toBe('fire:F123');
    expect((string) $alertId)->toBe('fire:F123');
});

test('alert id parses from string', function () {
    $alertId = AlertId::fromString('police:9001');

    expect($alertId->source)->toBe('police');
    expect($alertId->externalId)->toBe('9001');
    expect($alertId->value())->toBe('police:9001');
});

test('alert id accepts miway source', function () {
    $alertId = AlertId::fromParts('miway', 'miway:alert:12345');

    expect($alertId->source)->toBe('miway');
    expect($alertId->externalId)->toBe('miway:alert:12345');
    expect($alertId->value())->toBe('miway:miway:alert:12345');
});

test('alert id rejects invalid formats', function (string $value) {
    expect(fn () => AlertId::fromString($value))
        ->toThrow(\InvalidArgumentException::class);
})->with([
    'missing colon' => ['fire'],
    'empty source' => [':123'],
    'empty external id' => ['fire:'],
]);

test('alert id rejects invalid sources', function () {
    expect(fn () => AlertId::fromParts('unknown', '123'))
        ->toThrow(\InvalidArgumentException::class);
});
