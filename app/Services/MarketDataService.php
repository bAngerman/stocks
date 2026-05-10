<?php

namespace App\Services;

use App\Trading\MarketQuote;
use Illuminate\Support\Collection;
use Scheb\YahooFinanceApi\ApiClient;

class MarketDataService
{
    public function __construct(private readonly ApiClient $client) {}

    public function getQuote(string $ticker): MarketQuote
    {
        $quote = $this->client->getQuote($ticker);

        return new MarketQuote(
            ticker: $ticker,
            price: $quote->getRegularMarketPrice(),
            changePercent: $quote->getRegularMarketChangePercent(),
            fetchedAt: now(),
        );
    }

    /** @return Collection<int, MarketQuote> */
    public function getQuotes(array $tickers): Collection
    {
        return collect($tickers)->map(fn (string $ticker) => $this->getQuote($ticker));
    }
}
