<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonaPortfolioSnapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'persona_id',
        'total_value',
        'cash_balance',
        'snapshotted_at',
    ];

    protected $casts = [
        'total_value' => 'decimal:2',
        'cash_balance' => 'decimal:2',
        'snapshotted_at' => 'datetime',
    ];

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
    }
}
