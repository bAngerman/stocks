<?php

namespace App\Trading;

use Carbon\Carbon;

readonly class MarketQuote
{
    public function __construct(
        public string $ticker,
        public float $price,
        public float $changePercent,
        public Carbon $fetchedAt,
    ) {}
}
