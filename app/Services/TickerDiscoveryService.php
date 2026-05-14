<?php

namespace App\Services;

use App\Models\Persona;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class TickerDiscoveryService
{
    /**
     * Ask Claude for a pool of tradeable ticker candidates.
     *
     * @return array<int, array{ticker: string, rationale: string}>
     */
    public function discoverPool(int $count = 25): array
    {
        try {
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
        } catch (Throwable $e) {
            Log::warning('TickerDiscoveryService: Claude API error in discoverPool', ['error' => $e->getMessage()]);

            return [];
        }

        $text = $response->json('content.0.text', '');
        $text = preg_replace('/^```(?:\w+)?\n?|\n?```$/s', '', trim($text));
        $parsed = json_decode($text, true);

        if (! is_array($parsed)) {
            Log::warning('TickerDiscoveryService: unparseable pool response', ['response' => $text]);

            return [];
        }

        return $parsed;
    }

    /**
     * @param  array<int, array{ticker: string, rationale: string}>  $pool
     * @param  Collection<int, Persona>  $personas
     * @return array<string, string[]>
     */
    public function assignToPersonas(array $pool, Collection $personas): array
    {
        if (empty($pool) || $personas->isEmpty()) {
            return [];
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => config('services.anthropic.key'),
                'anthropic-version' => config('services.anthropic.version'),
                'content-type' => 'application/json',
            ])->timeout(30)->post('https://api.anthropic.com/v1/messages', [
                'model' => config('services.anthropic.model'),
                'max_tokens' => 512,
                'messages' => [['role' => 'user', 'content' => $this->buildAssignmentPrompt($pool, $personas)]],
            ]);

            $response->throw();
        } catch (Throwable $e) {
            Log::warning('TickerDiscoveryService: Claude API error in assignToPersonas', ['error' => $e->getMessage()]);

            return [];
        }

        $text = $response->json('content.0.text', '');
        $text = preg_replace('/^```(?:\w+)?\n?|\n?```$/s', '', trim($text));
        $parsed = json_decode($text, true);

        if (! is_array($parsed)) {
            Log::warning('TickerDiscoveryService: unparseable assignment response', ['response' => $text]);

            return [];
        }

        return $parsed;
    }

    private function buildDiscoveryPrompt(int $count): string
    {
        return <<<PROMPT
You are assisting an automated paper trading system. Suggest exactly {$count} US stock and ETF tickers worth watching for active intraday trading today. Focus on stocks with strong momentum, high volume, or notable volatility. Stocks and ETFs only — no options, no crypto.

Return a JSON array only — no other text. Keep each rationale under 10 words:
[{"ticker": "SYMBOL", "rationale": "Brief reason under 10 words"}, ...]
PROMPT;
    }

    private function buildAssignmentPrompt(array $pool, Collection $personas): string
    {
        $poolList = collect($pool)
            ->map(fn (array $t) => "- {$t['ticker']}: {$t['rationale']}")
            ->implode("\n");

        $personaList = $personas
            ->map(fn (Persona $p) => "- {$p->name} ({$p->strategy_type->value}): ".($p->description ?? $p->strategy_type->value))
            ->implode("\n");

        return <<<PROMPT
You are assigning stock tickers to trading personas for a paper trading system.

Available tickers (only assign from this list):
{$poolList}

Personas:
{$personaList}

For each persona, select the tickers from the list above that best match their strategy. Only assign tickers that appear in the available list above.

Return a JSON object only — no other text:
{"Persona Name": ["TICKER1", "TICKER2"], ...}
PROMPT;
    }
}
