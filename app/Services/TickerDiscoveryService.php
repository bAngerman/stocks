<?php

namespace App\Services;

use App\Models\Persona;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TickerDiscoveryService
{
    /**
     * Suggest new ticker candidates for a persona via the Claude API.
     *
     * @return array<array{ticker: string, rationale: string}>
     */
    public function suggest(Persona $persona): array
    {
        $prompt = $this->buildPrompt($persona);

        try {
            $response = Http::withHeaders([
                'x-api-key' => config('services.anthropic.key'),
                'anthropic-version' => config('services.anthropic.version'),
                'content-type' => 'application/json',
            ])->timeout(30)->post('https://api.anthropic.com/v1/messages', [
                'model' => config('services.anthropic.model'),
                'max_tokens' => 512,
                'messages' => [['role' => 'user', 'content' => $prompt]],
            ]);

            if ($response->failed()) {
                return [];
            }

            $text = $response->json('content.0.text', '');

            preg_match('/\[.*?\]/s', $text, $matches);

            if (empty($matches[0])) {
                return [];
            }

            $decoded = json_decode($matches[0], true);

            if (! is_array($decoded)) {
                return [];
            }

            return collect($decoded)
                ->filter(fn ($item) => isset($item['ticker'], $item['rationale']))
                ->map(fn ($item) => [
                    'ticker' => strtoupper(trim($item['ticker'])),
                    'rationale' => $item['rationale'],
                ])
                ->values()
                ->all();
        } catch (\Throwable $e) {
            Log::warning('TickerDiscoveryService: suggestion failed', [
                'persona_id' => $persona->id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    private function buildPrompt(Persona $persona): string
    {
        $activeTickers = $persona->activeTickers->pluck('ticker')->implode(', ');
        $candidateTickers = $persona->candidateTickers->pluck('ticker')->implode(', ') ?: 'none';
        $paramsJson = json_encode($persona->strategy_parameters, JSON_PRETTY_PRINT);

        return <<<PROMPT
You are advising a paper trading bot persona called "{$persona->name}" that uses a {$persona->strategy_type->value} strategy.

Strategy parameters:
{$paramsJson}

Current active watchlist: {$activeTickers}
Current candidates under evaluation: {$candidateTickers}

Suggest 3–5 new US-listed stock or ETF ticker symbols to add to the candidate watchlist. Do not suggest tickers already listed above. Respond with a JSON array only — no other text:
[{"ticker": "SYMBOL", "rationale": "Brief reason under 20 words"}]
PROMPT;
    }
}
