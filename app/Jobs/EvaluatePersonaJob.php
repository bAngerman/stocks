<?php

namespace App\Jobs;

use App\Enums\TickerStatus;
use App\Models\Persona;
use App\Models\PriceSnapshot;
use App\Services\MarketDataService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class EvaluatePersonaJob implements ShouldQueue
{
    use Queueable;

    private const CANDIDATE_MAX_EVALUATIONS = 20;

    public int $tries = 3;

    public function __construct(public readonly Persona $persona) {}

    public function handle(MarketDataService $marketDataService): void
    {
        Log::info('EvaluatePersonaJob: starting', ['persona_id' => $this->persona->id]);

        if (! $this->isDuringMarketHours()) {
            Log::info('EvaluatePersonaJob: outside market hours, skipping', ['persona_id' => $this->persona->id]);

            return;
        }

        $tickers = $this->persona->activeTickers->pluck('ticker')
            ->merge($this->persona->candidateTickers->pluck('ticker'))
            ->unique()
            ->values()
            ->all();

        if (empty($tickers)) {
            Log::info('EvaluatePersonaJob: no tickers, skipping', ['persona_id' => $this->persona->id]);

            return;
        }

        try {
            $snapshots = $this->getOrFetchSnapshots($tickers, $marketDataService);
        } catch (\Throwable $e) {
            Log::warning('EvaluatePersonaJob: market data fetch failed', [
                'persona_id' => $this->persona->id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $strategy = $this->persona->strategy_type->make();
        $signal = $strategy->evaluate($this->persona, $snapshots);

        if (! $signal) {
            Log::info('EvaluatePersonaJob: no signal generated', ['persona_id' => $this->persona->id]);
            $this->ageCandidates(null);

            return;
        }

        $this->promoteIfCandidate($signal->ticker);
        $this->ageCandidates($signal->ticker);

        $snapshot = $snapshots->firstWhere('ticker', $signal->ticker);

        if (! $snapshot) {
            Log::warning('EvaluatePersonaJob: signal ticker not in snapshots', [
                'persona_id' => $this->persona->id,
                'ticker' => $signal->ticker,
            ]);

            return;
        }

        if ($signal->shouldConsultAI) {
            Log::info('EvaluatePersonaJob: dispatching AI evaluation', [
                'persona_id' => $this->persona->id,
                'ticker' => $signal->ticker,
            ]);
            AIEvaluationJob::dispatch($this->persona, $signal, $snapshot);
        } else {
            Log::info('EvaluatePersonaJob: dispatching trade execution', [
                'persona_id' => $this->persona->id,
                'ticker' => $signal->ticker,
                'action' => $signal->action->value,
            ]);
            ExecuteTradeJob::dispatch($this->persona, $signal, (float) $snapshot->price);
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('EvaluatePersonaJob: failed', [
            'persona_id' => $this->persona->id,
            'error' => $e->getMessage(),
        ]);
    }

    private function isDuringMarketHours(): bool
    {
        $now = now()->setTimezone('America/New_York');

        if ($now->isWeekend()) {
            return false;
        }

        $minutesFromMidnight = $now->hour * 60 + $now->minute;

        // 570 = 9:30am, 960 = 4:00pm
        return $minutesFromMidnight >= 570 && $minutesFromMidnight < 960;
    }

    private function promoteIfCandidate(string $ticker): void
    {
        $isCandidate = $this->persona->candidateTickers()
            ->where('ticker', $ticker)
            ->exists();

        if ($isCandidate) {
            $this->persona->tickers()
                ->where('ticker', $ticker)
                ->update([
                    'status' => TickerStatus::Active->value,
                    'promoted_at' => now(),
                ]);
        }
    }

    private function ageCandidates(?string $signalTicker): void
    {
        $this->persona->candidateTickers()
            ->when($signalTicker, fn ($q) => $q->where('ticker', '!=', $signalTicker))
            ->increment('evaluations_without_signal');

        $this->persona->candidateTickers()
            ->where('evaluations_without_signal', '>=', self::CANDIDATE_MAX_EVALUATIONS)
            ->delete();
    }

    private function getOrFetchSnapshots(array $tickers, MarketDataService $marketDataService): Collection
    {
        $cutoff = now()->subMinutes(15);

        return collect($tickers)->map(function (string $ticker) use ($cutoff, $marketDataService) {
            $existing = PriceSnapshot::where('ticker', $ticker)
                ->where('fetched_at', '>=', $cutoff)
                ->latest('fetched_at')
                ->first();

            if ($existing) {
                return $existing;
            }

            $quote = $marketDataService->getQuote($ticker);

            return PriceSnapshot::create([
                'ticker' => $quote->ticker,
                'price' => $quote->price,
                'change_percent' => $quote->changePercent,
                'fetched_at' => $quote->fetchedAt,
            ]);
        });
    }
}
