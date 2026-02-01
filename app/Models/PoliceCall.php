<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PoliceCall extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'object_id',
        'call_type_code',
        'call_type',
        'division',
        'cross_streets',
        'latitude',
        'longitude',
        'occurrence_time',
        'is_active',
        'feed_updated_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'occurrence_time' => 'datetime',
        'feed_updated_at' => 'datetime',
        'is_active' => 'boolean',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
    ];

    /**
     * Scope a query to only include active calls.
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
