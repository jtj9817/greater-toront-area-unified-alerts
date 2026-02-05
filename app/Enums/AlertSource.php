<?php

namespace App\Enums;

enum AlertSource: string
{
    case Fire = 'fire';
    case Police = 'police';
    case Transit = 'transit';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $case) => $case->value,
            self::cases(),
        );
    }

    public static function isValid(?string $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        return self::tryFrom($value) !== null;
    }
}
