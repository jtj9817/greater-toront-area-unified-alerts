<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationLog extends Model
{
    /** @use HasFactory<\Database\Factories\NotificationLogFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'alert_id',
        'delivery_method',
        'status',
        'sent_at',
        'read_at',
        'dismissed_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'read_at' => 'datetime',
            'dismissed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function scopeUnread(Builder $query): void
    {
        $query->whereNull('read_at');
    }

    public function scopeUndismissed(Builder $query): void
    {
        $query->whereNull('dismissed_at');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
