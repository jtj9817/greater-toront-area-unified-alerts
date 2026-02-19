<?php

namespace App\Services\Alerts\DTOs;

use Carbon\CarbonImmutable;

readonly class UnifiedAlertsCursor
{
    public function __construct(
        public CarbonImmutable $timestamp,
        public string $id,
    ) {
        if ($this->id === '') {
            throw new \InvalidArgumentException('Cursor id is required.');
        }
    }

    public static function fromTuple(CarbonImmutable $timestamp, string $id): self
    {
        return new self($timestamp, $id);
    }

    public function encode(): string
    {
        $json = json_encode(
            [
                'ts' => $this->timestamp->toIso8601String(),
                'id' => $this->id,
            ],
            JSON_THROW_ON_ERROR,
        );

        return self::base64UrlEncode($json);
    }

    public static function decode(string $value): self
    {
        if ($value === '') {
            throw new \InvalidArgumentException('Cursor value is required.');
        }

        $decoded = self::base64UrlDecode($value);

        try {
            $payload = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            throw new \InvalidArgumentException('Cursor payload is invalid.', previous: $exception);
        }

        if (! is_array($payload)) {
            throw new \InvalidArgumentException('Cursor payload is invalid.');
        }

        $rawTimestamp = $payload['ts'] ?? null;
        $rawId = $payload['id'] ?? null;

        if (! is_string($rawTimestamp) || $rawTimestamp === '' || ! is_string($rawId) || $rawId === '') {
            throw new \InvalidArgumentException('Cursor payload is invalid.');
        }

        try {
            $timestamp = CarbonImmutable::parse($rawTimestamp);
        } catch (\Throwable $exception) {
            throw new \InvalidArgumentException('Cursor timestamp is invalid.', previous: $exception);
        }

        return new self($timestamp, $rawId);
    }

    private static function base64UrlEncode(string $value): string
    {
        $encoded = base64_encode($value);

        return rtrim(strtr($encoded, '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string
    {
        $normalized = strtr($value, '-_', '+/');
        $padded = str_pad($normalized, (int) (ceil(strlen($normalized) / 4) * 4), '=', STR_PAD_RIGHT);

        $decoded = base64_decode($padded, true);

        if ($decoded === false) {
            throw new \InvalidArgumentException('Cursor value is invalid.');
        }

        return $decoded;
    }
}

