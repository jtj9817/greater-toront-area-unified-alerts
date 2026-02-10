<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\Rule;

class NotificationPreference extends Model
{
    /** @use HasFactory<\Database\Factories\NotificationPreferenceFactory> */
    use HasFactory;

    public const ALERT_TYPES = [
        'all',
        'transit',
        'emergency',
        'accessibility',
    ];

    public const SEVERITY_THRESHOLDS = [
        'all',
        'minor',
        'major',
        'critical',
    ];

    protected $fillable = [
        'user_id',
        'alert_type',
        'severity_threshold',
        'geofences',
        'subscribed_routes',
        'digest_mode',
        'push_enabled',
    ];

    protected function casts(): array
    {
        return [
            'geofences' => 'array',
            'subscribed_routes' => 'array',
            'digest_mode' => 'boolean',
            'push_enabled' => 'boolean',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultAttributes(): array
    {
        return [
            'alert_type' => 'all',
            'severity_threshold' => 'all',
            'geofences' => [],
            'subscribed_routes' => [],
            'digest_mode' => false,
            'push_enabled' => true,
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function validationRules(bool $partial = false): array
    {
        $required = $partial ? ['sometimes'] : ['required'];

        return [
            'alert_type' => [...$required, 'string', Rule::in(self::ALERT_TYPES)],
            'severity_threshold' => [...$required, 'string', Rule::in(self::SEVERITY_THRESHOLDS)],
            'geofences' => [...$required, 'array'],
            'geofences.*' => ['array:name,lat,lng,radius_km'],
            'geofences.*.name' => ['nullable', 'string', 'max:120'],
            'geofences.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'geofences.*.lng' => ['required', 'numeric', 'between:-180,180'],
            'geofences.*.radius_km' => ['required', 'numeric', 'gt:0', 'max:100'],
            'subscribed_routes' => [...$required, 'array'],
            'subscribed_routes.*' => ['string', 'max:64'],
            'digest_mode' => [...$required, 'boolean'],
            'push_enabled' => [...$required, 'boolean'],
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
