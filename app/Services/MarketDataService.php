<?php

namespace App\Services;

use App\Trading\MarketQuote;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MarketDataService
{
    private string $apiKey;

    /** @var string[] */
    private array $watchlist;

    public function __construct()
    {
        $this->apiKey = config('services.finnhub.key');
        $this->watchlist = config('services.finnhub.watchlist', []);
    }

    public function getQuote(string $ticker): MarketQuote
    {
        $response = Http::withToken($this->apiKey)
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

    /**
     * @return Collection<int, array{ticker: string, changePercent: float, name: string}>
     */
    public function getGainers(int $limit = 25): Collection
    {
        if (empty($this->watchlist)) {
            return collect();
        }

        return collect($this->watchlist)
            ->map(function (string $ticker) {
                try {
                    $quote = $this->getQuote($ticker);

                    return [
                        'ticker' => $quote->ticker,
                        'changePercent' => $quote->changePercent,
                        'name' => $quote->ticker,
                    ];
                } catch (\Throwable $e) {
                    Log::warning('MarketDataService: getGainers quote failed', [
                        'ticker' => $ticker,
                        'error' => $e->getMessage(),
                    ]);

                    return null;
                }
            })
            ->filter()
            ->sortByDesc(fn (array $item) => $item['changePercent'])
            ->take($limit)
            ->values();
    }
}
