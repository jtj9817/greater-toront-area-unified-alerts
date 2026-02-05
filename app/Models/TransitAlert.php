<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransitAlert extends Model
{
    /** @use HasFactory<\Database\Factories\TransitAlertFactory> */
    use HasFactory;

    protected $fillable = [
        'external_id',
        'source_feed',
        'alert_type',
        'route_type',
        'route',
        'title',
        'description',
        'severity',
        'effect',
        'cause',
        'active_period_start',
        'active_period_end',
        'direction',
        'stop_start',
        'stop_end',
        'url',
        'is_active',
        'feed_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'active_period_start' => 'datetime',
            'active_period_end' => 'datetime',
            'feed_updated_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
