<?php

namespace App\Services;

use App\Trading\MarketQuote;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MarketDataService
{
    private string $apiKey;

    public function __construct()
    {
        $this->apiKey = config('services.polygon.key');
    }

    public function getQuote(string $ticker): MarketQuote
    {
        $response = Http::withToken($this->apiKey)
            ->get("https://api.polygon.io/v2/snapshot/locale/us/markets/stocks/tickers/{$ticker}")
            ->throw()
            ->json();

        $snapshot = $response['ticker'];

        return new MarketQuote(
            ticker: $ticker,
            price: (float) ($snapshot['lastTrade']['p'] ?? $snapshot['day']['c']),
            changePercent: (float) ($snapshot['todaysChangePerc'] ?? 0.0),
            fetchedAt: now(),
        );
    }

    /** @return Collection<int, MarketQuote> */
    public function getQuotes(array $tickers): Collection
    {
        return collect($tickers)->map(fn (string $ticker) => $this->getQuote($ticker));
    }

    /**
     * @return Collection<int, array{ticker: string, changePercent: float, name: string}>
     */
    public function getGainers(int $limit = 25): Collection
    {
        try {
            $response = Http::withToken($this->apiKey)
                ->timeout(15)
                ->get('https://api.polygon.io/v2/snapshot/locale/us/markets/stocks/gainers');

            if ($response->failed()) {
                return collect();
            }

            return collect($response->json('tickers') ?? [])
                ->sortByDesc(fn (array $item) => $item['todaysChangePerc'] ?? 0.0)
                ->take($limit)
                ->map(fn (array $item) => [
                    'ticker' => $item['ticker'],
                    'changePercent' => (float) ($item['todaysChangePerc'] ?? 0.0),
                    'name' => $item['ticker'],
                ])
                ->values();
        } catch (\Throwable $e) {
            Log::warning('MarketDataService: getGainers failed', ['error' => $e->getMessage()]);

            return collect();
        }
    }
}
