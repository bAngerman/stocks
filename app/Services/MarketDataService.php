<?php

namespace App\Services;

use App\Trading\MarketQuote;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

    /**
     * @return Collection<int, array{ticker: string, changePercent: float, name: string}>
     */
    public function getGainers(int $limit = 25): Collection
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.5',
            ])->timeout(15)->get('https://finance.yahoo.com/markets/stocks/gainers/');

            if ($response->failed()) {
                return collect();
            }

            preg_match('/trending-tickers">(\\[.*?\\])<\\/script/s', $response->body(), $matches);

            if (empty($matches[1])) {
                return collect();
            }

            $data = json_decode($matches[1], true);

            if (! is_array($data)) {
                return collect();
            }

            return collect($data)
                ->filter(fn ($item) => ($item['quoteType'] ?? '') === 'EQUITY')
                ->filter(fn ($item) => ! empty($item['symbol']))
                ->sortByDesc(fn ($item) => $item['regularMarketChangePercent']['raw'] ?? 0.0)
                ->take($limit)
                ->map(fn ($item) => [
                    'ticker' => $item['symbol'],
                    'changePercent' => (float) ($item['regularMarketChangePercent']['raw'] ?? 0.0),
                    'name' => $item['shortName'] ?? $item['symbol'],
                ])
                ->values();
        } catch (\Throwable $e) {
            Log::warning('MarketDataService: getGainers failed', ['error' => $e->getMessage()]);

            return collect();
        }
    }
}
