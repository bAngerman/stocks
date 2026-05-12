<?php

namespace App\Jobs;

use App\Enums\TradeAction;
use App\Models\Persona;
use App\Models\Trade;
use App\Trading\TradeSignal;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

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
        Log::info('ExecuteTradeJob: starting', [
            'persona_id' => $this->persona->id,
            'ticker' => $this->signal->ticker,
            'action' => $this->signal->action->value,
            'shares' => $this->signal->shares,
        ]);

        if ($this->signal->action === TradeAction::Buy) {
            $this->executeBuy();
        } else {
            $this->executeSell();
        }
    }

    public function failed(Throwable $e): void
    {
        Log::error('ExecuteTradeJob: failed', [
            'persona_id' => $this->persona->id,
            'ticker' => $this->signal->ticker,
            'action' => $this->signal->action->value,
            'error' => $e->getMessage(),
        ]);
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

        DB::transaction(function () use ($totalCost) {
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
        });

        Log::info('ExecuteTradeJob: buy executed', [
            'persona_id' => $this->persona->id,
            'ticker' => $this->signal->ticker,
            'shares' => $this->signal->shares,
            'price' => $this->pricePerShare,
            'total' => $totalCost,
        ]);
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

        DB::transaction(function () use ($position, $sharesToSell) {
            $position->shares = (float) $position->shares - $sharesToSell;
            $position->save();

            $this->persona->cash_balance = (float) $this->persona->cash_balance + ($sharesToSell * $this->pricePerShare);
            $this->persona->save();

            $this->recordTrade($sharesToSell);
        });

        Log::info('ExecuteTradeJob: sell executed', [
            'persona_id' => $this->persona->id,
            'ticker' => $this->signal->ticker,
            'shares' => $sharesToSell,
            'price' => $this->pricePerShare,
            'total' => round($sharesToSell * $this->pricePerShare, 2),
        ]);
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
