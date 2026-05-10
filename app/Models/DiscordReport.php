<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DiscordReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'period_start',
        'period_end',
        'payload',
        'posted_at',
    ];

    protected $casts = [
        'period_start' => 'datetime',
        'period_end' => 'datetime',
        'payload' => 'array',
        'posted_at' => 'datetime',
    ];
}
