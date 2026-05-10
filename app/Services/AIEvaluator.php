<?php

namespace App\Services;

use App\Models\Persona;
use App\Models\PriceSnapshot;
use App\Trading\TradeSignal;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AIEvaluator
{
    /**
     * Evaluate a trade signal using the Claude API.
     *
     * @return array{0: TradeSignal, 1: string}|null Returns [signal, rationale] or null if rejected.
     */
    public function evaluate(Persona $persona, TradeSignal $signal, PriceSnapshot $snapshot): ?array
    {
        $prompt = $this->buildPrompt($persona, $signal, $snapshot);

        $response = Http::withHeaders([
            'x-api-key' => config('services.anthropic.key'),
            'anthropic-version' => config('services.anthropic.version'),
            'content-type' => 'application/json',
        ])->timeout(30)->post('https://api.anthropic.com/v1/messages', [
            'model' => config('services.anthropic.model'),
            'max_tokens' => 256,
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ]);

        $response->throw();

        $text = $response->json('content.0.text', '');
        $parsed = json_decode($text, true);

        if (! is_array($parsed) || ! isset($parsed['decision'])) {
            Log::warning('AIEvaluator: unparseable response', ['response' => $text]);

            return null;
        }

        return match ($parsed['decision']) {
            'approve' => [$signal, $parsed['rationale'] ?? ''],
            'modify' => [
                new TradeSignal(
                    ticker: $signal->ticker,
                    action: $signal->action,
                    shares: (float) ($parsed['shares'] ?? $signal->shares),
                    reason: $signal->reason,
                    confidence: $signal->confidence,
                    shouldConsultAI: false,
                ),
                $parsed['rationale'] ?? '',
            ],
            default => null,
        };
    }

    private function buildPrompt(Persona $persona, TradeSignal $signal, PriceSnapshot $snapshot): string
    {
        $openPositions = $persona->openPositions()->where('ticker', $signal->ticker)->first();
        $currentPosition = $openPositions
            ? "{$openPositions->shares} shares @ avg \${$openPositions->average_cost}"
            : 'none';

        return <<<PROMPT
You are evaluating a trade signal for a paper trading bot.

Persona: {$persona->name} ({$persona->strategy_type->value} strategy)
Cash balance: \${$persona->cash_balance}
Current position in {$signal->ticker}: {$currentPosition}

Trade signal:
- Action: {$signal->action->value}
- Ticker: {$signal->ticker}
- Shares: {$signal->shares}
- Current price: \${$snapshot->price}
- Intraday change: {$snapshot->change_percent}%
- Algorithm confidence: {$signal->confidence}
- Algorithm reason: {$signal->reason}

Respond with a JSON object only — no other text:
{"decision": "approve" | "modify" | "reject", "shares": <number if modifying>, "rationale": "<brief explanation>"}
PROMPT;
    }
}
