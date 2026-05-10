<?php

namespace App\Trading;

use App\Models\Persona;
use Illuminate\Support\Collection;

interface StrategyInterface
{
    public function evaluate(Persona $persona, Collection $snapshots): ?TradeSignal;
}
