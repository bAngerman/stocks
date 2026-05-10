<?php

namespace App\Trading\Strategies;

use App\Enums\TradeAction;
use App\Models\Persona;
use App\Trading\StrategyInterface;
use App\Trading\TradeSignal;
use Illuminate\Support\Collection;

class MomentumStrategy implements StrategyInterface
{
    public function evaluate(Persona $persona, Collection $snapshots): ?TradeSignal
    {
        $params = $persona->strategy_parameters;
        $buyThreshold = (float) ($params['buy_threshold'] ?? 1.5);
        $sellThreshold = (float) ($params['sell_threshold'] ?? 2.0);
        $aiMin = (float) ($params['ai_confidence_min'] ?? 0.4);
        $aiMax = (float) ($params['ai_confidence_max'] ?? 0.7);
        $sharesPerTrade = (float) ($params['shares_per_trade'] ?? 1);

        $bestSignal = null;
        $bestConfidence = 0.0;

        foreach ($params['tickers'] ?? [] as $ticker) {
            $snapshot = $snapshots->firstWhere('ticker', $ticker);
            if (! $snapshot) {
                continue;
            }

            $changePercent = (float) $snapshot->change_percent;
            $openPosition = $persona->openPositions()->where('ticker', $ticker)->first();

            if ($openPosition && $changePercent <= -$sellThreshold) {
                $confidence = min(abs($changePercent) / ($sellThreshold * 2), 1.0);
                if ($confidence > $bestConfidence) {
                    $bestConfidence = $confidence;
                    $bestSignal = new TradeSignal(
                        ticker: $ticker,
                        action: TradeAction::Sell,
                        shares: (float) $openPosition->shares,
                        reason: "Price dropped {$changePercent}% (threshold: -{$sellThreshold}%)",
                        confidence: $confidence,
                        shouldConsultAI: $confidence >= $aiMin && $confidence <= $aiMax,
                    );
                }
            } elseif (! $openPosition && $changePercent >= $buyThreshold) {
                $confidence = min($changePercent / ($buyThreshold * 2), 1.0);
                if ($confidence > $bestConfidence) {
                    $bestConfidence = $confidence;
                    $bestSignal = new TradeSignal(
                        ticker: $ticker,
                        action: TradeAction::Buy,
                        shares: $sharesPerTrade,
                        reason: "Price up {$changePercent}% (threshold: {$buyThreshold}%)",
                        confidence: $confidence,
                        shouldConsultAI: $confidence >= $aiMin && $confidence <= $aiMax,
                    );
                }
            }
        }

        return $bestSignal;
    }
}
