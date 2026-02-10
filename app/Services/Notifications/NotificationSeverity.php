<?php

namespace App\Services\Notifications;

class NotificationSeverity
{
    /**
     * @var array<string, int>
     */
    private const RANKS = [
        'all' => 0,
        'minor' => 1,
        'major' => 2,
        'critical' => 3,
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
