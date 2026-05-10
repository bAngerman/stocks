<?php

namespace App\Models;

use App\Enums\StrategyType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Persona extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'cash_balance',
        'strategy_type',
        'strategy_parameters',
        'is_active',
    ];

    protected $casts = [
        'strategy_type' => StrategyType::class,
        'strategy_parameters' => 'array',
        'cash_balance' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }

    public function trades(): HasMany
    {
        return $this->hasMany(Trade::class);
    }

    public function openPositions(): HasMany
    {
        return $this->positions()->where('shares', '>', 0);
    }
}
