<?php

namespace App\Console\Commands;

use App\Enums\TradeAction;
use App\Models\Trade;
use Illuminate\Console\Command;

class BackfillTradeCostBasis extends Command
{
    protected $signature = 'trades:backfill-cost-basis';

    protected $description = 'Backfill cost_basis on sell trades by replaying per-persona trade history';

    public function handle(): int
    {
        $combos = Trade::where('action', TradeAction::Sell)
            ->whereNull('cost_basis')
            ->select('persona_id', 'ticker')
            ->distinct()
            ->get();

        if ($combos->isEmpty()) {
            $this->info('No sell trades need backfilling.');

            return self::SUCCESS;
        }

        $updated = 0;

        foreach ($combos as $combo) {
            $trades = Trade::where('persona_id', $combo->persona_id)
                ->where('ticker', $combo->ticker)
                ->orderBy('executed_at')
                ->orderBy('id')
                ->get();

            $currentShares = 0.0;
            $currentAvgCost = 0.0;

            foreach ($trades as $trade) {
                if ($trade->action === TradeAction::Buy) {
                    $newShares = (float) $trade->shares;
                    $currentAvgCost = ($currentShares * $currentAvgCost + $newShares * (float) $trade->price_per_share)
                        / ($currentShares + $newShares);
                    $currentShares += $newShares;
                } else {
                    if ($trade->cost_basis === null) {
                        $trade->update(['cost_basis' => $currentAvgCost ?: null]);
                        $updated++;
                    }

                    $currentShares = max(0.0, $currentShares - (float) $trade->shares);

                    if ($currentShares === 0.0) {
                        $currentAvgCost = 0.0;
                    }
                }
            }
        }

        $this->info("Backfilled cost_basis on {$updated} sell trade(s).");

        return self::SUCCESS;
    }
}
