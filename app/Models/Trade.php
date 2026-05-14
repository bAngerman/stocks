<?php

namespace App\Models;

use App\Enums\TradeAction;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Trade extends Model
{
    use HasFactory;

    protected $fillable = [
        'persona_id',
        'ticker',
        'action',
        'shares',
        'price_per_share',
        'cost_basis',
        'total_value',
        'signal_reason',
        'ai_rationale',
        'executed_at',
    ];

    protected $casts = [
        'action' => TradeAction::class,
        'shares' => 'decimal:4',
        'price_per_share' => 'decimal:4',
        'cost_basis' => 'decimal:4',
        'total_value' => 'decimal:2',
        'executed_at' => 'datetime',
    ];

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
    }
}
