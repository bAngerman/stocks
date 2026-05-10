<?php

namespace App\Enums;

use App\Trading\StrategyInterface;

enum StrategyType: string
{
    case Momentum = 'momentum';
    case MeanReversion = 'mean_reversion';

    public function strategyClass(): string
    {
        return match ($this) {
            self::Momentum => 'App\Trading\Strategies\MomentumStrategy',
            self::MeanReversion => 'App\Trading\Strategies\MeanReversionStrategy',
        };
    }

    public function make(): StrategyInterface
    {
        return app($this->strategyClass());
    }
}
