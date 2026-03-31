<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MiwayAlert extends Model
{
    /** @use HasFactory<\Database\Factories\MiwayAlertFactory> */
    use HasFactory;

    protected $fillable = [
        'external_id',
        'header_text',
        'description_text',
        'cause',
        'effect',
        'starts_at',
        'ends_at',
        'url',
        'detour_pdf_url',
        'is_active',
        'feed_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'feed_updated_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
