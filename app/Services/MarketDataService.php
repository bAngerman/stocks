<?php

namespace App\Services;

use App\Trading\MarketQuote;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class MarketDataService
{
    private string $apiKey;

    public function __construct()
    {
        $this->apiKey = config('services.finnhub.key');
    }

    public function getQuote(string $ticker): MarketQuote
    {
        $response = Http::withHeaders(['X-Finnhub-Token' => $this->apiKey])
            ->get('https://finnhub.io/api/v1/quote', ['symbol' => $ticker])
            ->throw()
            ->json();

        return new MarketQuote(
            ticker: $ticker,
            price: (float) $response['c'],
            changePercent: (float) $response['dp'],
            fetchedAt: now(),
        );
    }

    /** @return Collection<int, MarketQuote> */
    public function getQuotes(array $tickers): Collection
    {
        return collect($tickers)->map(fn (string $ticker) => $this->getQuote($ticker));
    }
}
