<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TorontoAddress extends Model
{
    /** @use HasFactory<\Database\Factories\TorontoAddressFactory> */
    use HasFactory;

    protected $fillable = [
        'street_num',
        'street_name',
        'lat',
        'long',
        'zip',
    ];

    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'long' => 'float',
        ];
    }
}
