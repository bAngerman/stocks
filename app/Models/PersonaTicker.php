<?php

namespace App\Models;

use App\Enums\TickerSource;
use App\Enums\TickerStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonaTicker extends Model
{
    protected $attributes = [
        'evaluations_without_signal' => 0,
    ];

    protected $fillable = [
        'persona_id',
        'ticker',
        'status',
        'source',
        'ai_rationale',
        'evaluations_without_signal',
        'promoted_at',
    ];

    protected $casts = [
        'status' => TickerStatus::class,
        'source' => TickerSource::class,
        'evaluations_without_signal' => 'integer',
        'promoted_at' => 'datetime',
    ];

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
    }
}
