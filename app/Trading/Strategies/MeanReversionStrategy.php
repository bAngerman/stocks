<?php

namespace App\Trading\Strategies;

use App\Enums\TradeAction;
use App\Models\Persona;
use App\Models\PriceSnapshot;
use App\Trading\StrategyInterface;
use App\Trading\TradeSignal;
use Illuminate\Support\Collection;

class MeanReversionStrategy implements StrategyInterface
{
    public function evaluate(Persona $persona, Collection $snapshots): ?TradeSignal
    {
        $params = $persona->strategy_parameters;
        $lookbackPeriods = (int) ($params['lookback_periods'] ?? 20);
        $minDataPoints = (int) ($params['min_data_points'] ?? 10);
        $deviationThreshold = (float) ($params['deviation_threshold'] ?? 3.0);
        $sharesPerTrade = (float) ($params['shares_per_trade'] ?? 1);
        $aiMin = (float) ($params['ai_confidence_min'] ?? 0.4);
        $aiMax = (float) ($params['ai_confidence_max'] ?? 0.7);

        $bestSignal = null;
        $bestConfidence = 0.0;

        foreach ($snapshots as $snapshot) {
            $ticker = $snapshot->ticker;
            $currentPrice = (float) $snapshot->price;

            $history = PriceSnapshot::where('ticker', $ticker)
                ->where('fetched_at', '<', $snapshot->fetched_at)
                ->orderBy('fetched_at', 'desc')
                ->limit($lookbackPeriods)
                ->get();

            if ($history->count() < $minDataPoints) {
                continue;
            }

            $mean = (float) $history->avg('price');

            if ($mean <= 0) {
                continue;
            }

            $deviation = ($currentPrice - $mean) / $mean * 100;
            $openPosition = $persona->openPositions()->where('ticker', $ticker)->first();

            if (! $openPosition && $deviation < -$deviationThreshold) {
                $confidence = min(abs($deviation) / ($deviationThreshold * 2), 1.0);
                if ($confidence > $bestConfidence) {
                    $bestConfidence = $confidence;
                    $formattedMean = number_format($mean, 2);
                    $formattedDeviation = number_format(abs($deviation), 2);
                    $bestSignal = new TradeSignal(
                        ticker: $ticker,
                        action: TradeAction::Buy,
                        shares: $sharesPerTrade,
                        reason: "Price {$formattedDeviation}% below {$lookbackPeriods}-period mean of \${$formattedMean}",
                        confidence: $confidence,
                        shouldConsultAI: $confidence >= $aiMin && $confidence <= $aiMax,
                    );
                }
            } elseif ($openPosition && $deviation > $deviationThreshold) {
                $confidence = min($deviation / ($deviationThreshold * 2), 1.0);
                if ($confidence > $bestConfidence) {
                    $bestConfidence = $confidence;
                    $formattedMean = number_format($mean, 2);
                    $formattedDeviation = number_format($deviation, 2);
                    $bestSignal = new TradeSignal(
                        ticker: $ticker,
                        action: TradeAction::Sell,
                        shares: (float) $openPosition->shares,
                        reason: "Price {$formattedDeviation}% above {$lookbackPeriods}-period mean of \${$formattedMean}",
                        confidence: $confidence,
                        shouldConsultAI: $confidence >= $aiMin && $confidence <= $aiMax,
                    );
                }
            }
        }

        return $bestSignal;
    }
}
