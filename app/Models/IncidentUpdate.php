<?php

namespace App\Models;

use App\Enums\IncidentUpdateType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentUpdate extends Model
{
    /** @use HasFactory<\Database\Factories\IncidentUpdateFactory> */
    use HasFactory;

    protected $fillable = [
        'event_num',
        'update_type',
        'content',
        'metadata',
        'source',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'update_type' => IncidentUpdateType::class,
            'metadata' => 'array',
            'created_by' => 'integer',
        ];
    }

    public function fireIncident(): BelongsTo
    {
        return $this->belongsTo(FireIncident::class, 'event_num', 'event_num');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeForIncident(Builder $query, string $eventNum): void
    {
        $query->where('event_num', $eventNum);
    }

    public function scopeRecent(Builder $query, int $limit = 10): void
    {
        $query->orderByDesc('created_at')->limit($limit);
    }
}
