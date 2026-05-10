<?php

namespace App\Enums;

use App\Trading\Strategies\MeanReversionStrategy;
use App\Trading\Strategies\MomentumStrategy;
use App\Trading\StrategyInterface;

enum StrategyType: string
{
    case Momentum = 'momentum';
    case MeanReversion = 'mean_reversion';

    public function strategyClass(): string
    {
        return match ($this) {
            self::Momentum => MomentumStrategy::class,
            self::MeanReversion => MeanReversionStrategy::class,
        };
    }

    public function make(): StrategyInterface
    {
        return app($this->strategyClass());
    }
}
