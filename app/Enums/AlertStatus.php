<?php

namespace App\Enums;

enum AlertStatus: string
{
    case All = 'all';
    case Active = 'active';
    case Cleared = 'cleared';

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

    public static function normalize(?string $value, self $default = self::All): string
    {
        if ($value === null || $value === '') {
            return $default->value;
        }

        $case = self::tryFrom($value);
        if ($case === null) {
            $expected = implode(', ', self::values());
            throw new \InvalidArgumentException("Invalid status '{$value}'. Expected one of: {$expected}.");
        }

        return $case->value;
    }
}
