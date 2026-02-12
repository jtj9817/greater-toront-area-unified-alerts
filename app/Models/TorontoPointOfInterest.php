<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TorontoPointOfInterest extends Model
{
    /** @use HasFactory<\Database\Factories\TorontoPointOfInterestFactory> */
    use HasFactory;

    protected $table = 'toronto_pois';

    protected $fillable = [
        'name',
        'category',
        'lat',
        'long',
    ];

    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'long' => 'float',
        ];
    }
}
