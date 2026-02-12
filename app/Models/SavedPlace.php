<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\Rule;

class SavedPlace extends Model
{
    /** @use HasFactory<\Database\Factories\SavedPlaceFactory> */
    use HasFactory;

    public const TYPES = [
        'address',
        'poi',
        'manual',
        'legacy_geofence',
    ];

    protected $fillable = [
        'user_id',
        'name',
        'lat',
        'long',
        'radius',
        'type',
    ];

    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'long' => 'float',
            'radius' => 'integer',
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function validationRules(bool $partial = false): array
    {
        $required = $partial ? ['sometimes'] : ['required'];

        return [
            'name' => [...$required, 'string', 'max:120'],
            'lat' => [...$required, 'numeric', 'between:43.0000,44.5000'],
            'long' => [...$required, 'numeric', 'between:-80.2500,-78.7500'],
            'radius' => [...$required, 'integer', 'between:100,100000'],
            'type' => [...$required, 'string', Rule::in(self::TYPES)],
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
