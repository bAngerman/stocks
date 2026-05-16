<?php

namespace App\Models;

use Database\Factories\GamificationPostFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GamificationPost extends Model
{
    /** @use HasFactory<GamificationPostFactory> */
    use HasFactory;

    protected $fillable = [
        'trade_id',
        'discord_message_id',
        'resolved_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class);
    }
}
