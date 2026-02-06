<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GoTransitAlert extends Model
{
    /** @use HasFactory<\Database\Factories\GoTransitAlertFactory> */
    use HasFactory;

    protected $fillable = [
        'external_id',
        'alert_type',
        'service_mode',
        'corridor_or_route',
        'corridor_code',
        'sub_category',
        'message_subject',
        'message_body',
        'direction',
        'trip_number',
        'delay_duration',
        'status',
        'line_colour',
        'posted_at',
        'is_active',
        'feed_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'posted_at' => 'datetime',
            'feed_updated_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
