<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WeatherCache extends Model
{
    /** Default cache TTL in minutes. */
    public const int TTL_MINUTES = 30;

    protected $fillable = ['fsa', 'provider', 'payload', 'fetched_at'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'fetched_at' => 'datetime',
        ];
    }

    /**
     * Return true if this entry was fetched within the given TTL window.
     */
    public function isFresh(int $ttlMinutes = self::TTL_MINUTES): bool
    {
        return $this->fetched_at->gt(now()->subMinutes($ttlMinutes));
    }

    /**
     * Return the most recently fetched valid cache entry for the given FSA
     * and provider, or null if no unexpired entry exists.
     */
    public static function findValid(string $fsa, string $provider, int $ttlMinutes = self::TTL_MINUTES): ?static
    {
        return static::query()
            ->where('fsa', $fsa)
            ->where('provider', $provider)
            ->where('fetched_at', '>=', now()->subMinutes($ttlMinutes))
            ->orderByDesc('fetched_at')
            ->first();
    }
}
