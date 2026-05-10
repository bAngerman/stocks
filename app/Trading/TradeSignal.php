<?php

namespace App\Trading;

use App\Enums\TradeAction;

readonly class TradeSignal
{
    public function __construct(
        public string $ticker,
        public TradeAction $action,
        public float $shares,
        public string $reason,
        public float $confidence,
        public bool $shouldConsultAI,
    ) {}
}
