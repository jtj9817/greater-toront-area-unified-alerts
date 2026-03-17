<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedAlert extends Model
{
    /** @use HasFactory<\Database\Factories\SavedAlertFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'alert_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
