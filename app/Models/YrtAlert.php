<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class YrtAlert extends Model
{
    /** @use HasFactory<\Database\Factories\YrtAlertFactory> */
    use HasFactory;

    protected $fillable = [
        'external_id',
        'title',
        'posted_at',
        'details_url',
        'description_excerpt',
        'route_text',
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
