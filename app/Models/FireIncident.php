<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FireIncident extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_num',
        'event_type',
        'prime_street',
        'cross_streets',
        'dispatch_time',
        'alarm_level',
        'beat',
        'units_dispatched',
        'is_active',
        'feed_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'dispatch_time' => 'datetime',
            'feed_updated_at' => 'datetime',
            'alarm_level' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function incidentUpdates(): HasMany
    {
        return $this->hasMany(IncidentUpdate::class, 'event_num', 'event_num');
    }
}
