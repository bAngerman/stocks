<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PriceSnapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'ticker',
        'price',
        'change_percent',
        'fetched_at',
    ];

    protected $casts = [
        'price' => 'decimal:4',
        'change_percent' => 'decimal:4',
        'fetched_at' => 'datetime',
    ];
}
