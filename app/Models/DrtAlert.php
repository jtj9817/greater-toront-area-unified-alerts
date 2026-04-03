<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DrtAlert extends Model
{
    /** @use HasFactory<\Database\Factories\DrtAlertFactory> */
    use HasFactory;

    protected $fillable = [
        'external_id',
        'title',
        'posted_at',
        'when_text',
        'route_text',
        'details_url',
        'body_text',
        'list_hash',
        'details_fetched_at',
        'is_active',
        'feed_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'posted_at' => 'datetime',
            'details_fetched_at' => 'datetime',
            'feed_updated_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
