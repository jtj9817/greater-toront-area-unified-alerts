<?php

use App\Services\Alerts\DTOs\UnifiedAlertsCursor;
use Carbon\CarbonImmutable;

test('unified alerts cursor encodes and decodes round-trip', function () {
    $cursor = UnifiedAlertsCursor::fromTuple(
        CarbonImmutable::parse('2026-02-02 12:00:00'),
        'fire:FIRE-0001',
    );

    $encoded = $cursor->encode();

    $decoded = UnifiedAlertsCursor::decode($encoded);

    expect($decoded->id)->toBe('fire:FIRE-0001');
    expect($decoded->timestamp->toIso8601String())->toBe($cursor->timestamp->toIso8601String());
});

test('unified alerts cursor rejects invalid payloads', function (string $value) {
    expect(fn () => UnifiedAlertsCursor::decode($value))
        ->toThrow(\InvalidArgumentException::class);
})->with([
    'empty' => [''],
    'not base64' => ['%%%'],
    'not json' => [rtrim(strtr(base64_encode('nope'), '+/', '-_'), '=')],
    'missing keys' => [rtrim(strtr(base64_encode(json_encode(['ts' => '2026-02-02T12:00:00Z'])), '+/', '-_'), '=')],
    'invalid timestamp' => [rtrim(strtr(base64_encode(json_encode(['ts' => 'bad', 'id' => 'fire:1'])), '+/', '-_'), '=')],
]);

