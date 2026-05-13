<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TickerDiscoveryService
{
    /**
     * Ask Claude for a pool of tradeable ticker candidates.
     *
     * @return array<int, array{ticker: string, name: string, rationale: string}>
     */
    public function discoverPool(int $count = 25): array
    {
        $response = Http::withHeaders([
            'x-api-key' => config('services.anthropic.key'),
            'anthropic-version' => config('services.anthropic.version'),
            'content-type' => 'application/json',
        ])->timeout(30)->post('https://api.anthropic.com/v1/messages', [
            'model' => config('services.anthropic.model'),
            'max_tokens' => 1024,
            'messages' => [['role' => 'user', 'content' => $this->buildDiscoveryPrompt($count)]],
        ]);

        $response->throw();

        $text = $response->json('content.0.text', '');
        $text = preg_replace('/^```(?:\w+)?\n?|\n?```$/s', '', trim($text));
        $parsed = json_decode($text, true);

        if (! is_array($parsed)) {
            Log::warning('TickerDiscoveryService: unparseable pool response', ['response' => $text]);

            return [];
        }

        return $parsed;
    }

    private function buildDiscoveryPrompt(int $count): string
    {
        return <<<PROMPT
You are assisting an automated paper trading system. Suggest exactly {$count} US stock and ETF tickers worth watching for active intraday trading today. Focus on stocks with strong momentum, high volume, or notable volatility. Stocks and ETFs only — no options, no crypto.

Return a JSON array only — no other text:
[{"ticker": "SYMBOL", "name": "Company Name", "rationale": "Brief reason"}, ...]
PROMPT;
    }
}
