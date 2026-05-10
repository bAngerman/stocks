<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Position extends Model
{
    use HasFactory;

    protected $fillable = [
        'persona_id',
        'ticker',
        'shares',
        'average_cost',
        'opened_at',
    ];

    protected $casts = [
        'shares' => 'decimal:4',
        'average_cost' => 'decimal:4',
        'opened_at' => 'datetime',
    ];

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
    }
}
