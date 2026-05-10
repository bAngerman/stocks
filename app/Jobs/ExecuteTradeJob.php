<?php

namespace App\Jobs;

use App\Enums\TradeAction;
use App\Models\Persona;
use App\Models\Trade;
use App\Trading\TradeSignal;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ExecuteTradeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly Persona $persona,
        public readonly TradeSignal $signal,
        public readonly float $pricePerShare,
        public readonly ?string $aiRationale = null,
    ) {}

    public function handle(): void
    {
        if ($this->signal->action === TradeAction::Buy) {
            $this->executeBuy();
        } else {
            $this->executeSell();
        }
    }

    private function executeBuy(): void
    {
        $totalCost = $this->signal->shares * $this->pricePerShare;

        if ((float) $this->persona->cash_balance < $totalCost) {
            Log::warning('ExecuteTradeJob: insufficient cash', [
                'persona_id' => $this->persona->id,
                'ticker' => $this->signal->ticker,
                'required' => $totalCost,
                'available' => $this->persona->cash_balance,
            ]);

            return;
        }

        $position = $this->persona->positions()
            ->firstOrNew(['ticker' => $this->signal->ticker]);

        if (! $position->exists) {
            $position->average_cost = $this->pricePerShare;
            $position->shares = 0;
            $position->opened_at = now();
        } else {
            $existingShares = (float) $position->shares;
            $existingCost = (float) $position->average_cost;
            $newShares = $this->signal->shares;
            $position->average_cost = (($existingShares * $existingCost) + ($newShares * $this->pricePerShare)) / ($existingShares + $newShares);
        }

        $position->shares = (float) $position->shares + $this->signal->shares;
        $position->save();

        $this->persona->cash_balance = (float) $this->persona->cash_balance - $totalCost;
        $this->persona->save();

        $this->recordTrade($this->signal->shares);
    }

    private function executeSell(): void
    {
        $position = $this->persona->openPositions()
            ->where('ticker', $this->signal->ticker)
            ->first();

        if (! $position) {
            return;
        }

        $sharesToSell = min($this->signal->shares, (float) $position->shares);

        $position->shares = (float) $position->shares - $sharesToSell;
        $position->save();

        $this->persona->cash_balance = (float) $this->persona->cash_balance + ($sharesToSell * $this->pricePerShare);
        $this->persona->save();

        $this->recordTrade($sharesToSell);
    }

    private function recordTrade(float $shares): void
    {
        Trade::create([
            'persona_id' => $this->persona->id,
            'ticker' => $this->signal->ticker,
            'action' => $this->signal->action,
            'shares' => $shares,
            'price_per_share' => $this->pricePerShare,
            'total_value' => round($shares * $this->pricePerShare, 2),
            'signal_reason' => $this->signal->reason,
            'ai_rationale' => $this->aiRationale,
            'executed_at' => now(),
        ]);
    }
}
