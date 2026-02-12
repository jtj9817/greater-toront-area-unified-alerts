<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SavedPlace extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'lat' => 'float',
        'lng' => 'float',
        'radius_km' => 'float',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
