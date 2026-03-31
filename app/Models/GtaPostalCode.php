<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class GtaPostalCode extends Model
{
    public $timestamps = false;

    protected $fillable = ['fsa', 'municipality', 'neighbourhood', 'lat', 'lng'];

    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
        ];
    }

    /**
     * Normalise an FSA or postal code string to a 3-character uppercase FSA.
     *
     * Examples: "M5V 1A1" → "M5V", "m5v" → "M5V", "m 5 v" → "M5V"
     */
    public static function normalize(string $input): string
    {
        $clean = preg_replace('/[^A-Za-z0-9]/', '', $input);

        return strtoupper(substr($clean, 0, 3));
    }

    /**
     * Escape special LIKE characters.
     */
    private static function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    /**
     * Search by FSA (exact after normalization), municipality, or neighbourhood.
     *
     * FSA exact matches are ranked first; remaining results are ordered
     * by municipality then FSA.
     *
     * Works across SQLite, MySQL, and PostgreSQL.
     */
    public static function search(string $query): Builder
    {
        $driver = DB::getDriverName();
        $normalizedFsa = static::normalize($query);
        $likeOp = $driver === 'pgsql' ? 'ILIKE' : 'LIKE';
        $escapedQuery = static::escapeLike($query);
        $pattern = '%'.$escapedQuery.'%';

        return static::query()
            ->where(function (Builder $q) use ($normalizedFsa, $likeOp, $pattern) {
                $q->where('fsa', $normalizedFsa)
                    ->orWhereRaw("municipality {$likeOp} ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("neighbourhood {$likeOp} ? ESCAPE '!'", [$pattern]);
            })
            ->orderByRaw('CASE WHEN fsa = ? THEN 0 ELSE 1 END', [$normalizedFsa])
            ->orderBy('municipality')
            ->orderBy('fsa');
    }

    /**
     * Return the FSA record whose centroid is closest to the given coordinates.
     *
     * Uses squared Euclidean distance as a cheap approximation suitable for
     * the relatively small GTA geographic area (~50 km radius).
     *
     * Works across SQLite, MySQL, and PostgreSQL.
     */
    public static function nearestFsa(float $lat, float $lng): ?static
    {
        return static::query()
            ->orderByRaw('(lat - ?) * (lat - ?) + (lng - ?) * (lng - ?)', [$lat, $lat, $lng, $lng])
            ->first();
    }
}
