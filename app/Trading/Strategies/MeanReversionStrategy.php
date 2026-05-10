<?php

namespace App\Trading\Strategies;

use App\Models\Persona;
use App\Trading\StrategyInterface;
use App\Trading\TradeSignal;
use Illuminate\Support\Collection;

class MeanReversionStrategy implements StrategyInterface
{
    public function evaluate(Persona $persona, Collection $snapshots): ?TradeSignal
    {
        throw new \RuntimeException('MeanReversionStrategy is not yet implemented.');
    }
}
