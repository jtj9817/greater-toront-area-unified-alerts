<?php

namespace App\Services\Notifications;

class NotificationSeverity
{
    public const ALL = 'all';

    public const MINOR = 'minor';

    public const MAJOR = 'major';

    public const CRITICAL = 'critical';

    /**
     * @var array<string, int>
     */
    private const RANKS = [
        self::ALL => 0,
        self::MINOR => 1,
        self::MAJOR => 2,
        self::CRITICAL => 3,
    ];

    public static function normalize(string $value): string
    {
        $normalized = strtolower(trim($value));

        return array_key_exists($normalized, self::RANKS)
            ? $normalized
            : 'minor';
    }

    public static function rank(string $value): int
    {
        return self::RANKS[self::normalize($value)];
    }
}
