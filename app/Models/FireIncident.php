<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FireIncident extends Model
{
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

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
