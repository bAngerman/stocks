<?php

namespace App\Jobs;

use App\Models\Persona;
use App\Models\PriceSnapshot;
use App\Services\AIEvaluator;
use App\Trading\TradeSignal;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class AIEvaluationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public function __construct(
        public readonly Persona $persona,
        public readonly TradeSignal $signal,
        public readonly PriceSnapshot $snapshot,
    ) {}

    public function handle(AIEvaluator $evaluator): void
    {
        Log::info('AIEvaluationJob: starting', [
            'persona_id' => $this->persona->id,
            'ticker' => $this->signal->ticker,
        ]);

        $result = $evaluator->evaluate($this->persona, $this->signal, $this->snapshot);

        if (! $result) {
            Log::info('AIEvaluationJob: signal rejected by AI', [
                'persona_id' => $this->persona->id,
                'ticker' => $this->signal->ticker,
            ]);

            return;
        }

        [$resolvedSignal, $rationale] = $result;

        Log::info('AIEvaluationJob: signal approved by AI, dispatching trade', [
            'persona_id' => $this->persona->id,
            'ticker' => $this->signal->ticker,
            'action' => $resolvedSignal->action->value,
        ]);

        ExecuteTradeJob::dispatch(
            $this->persona,
            $resolvedSignal,
            (float) $this->snapshot->price,
            $rationale,
        );
    }

    public function failed(Throwable $e): void
    {
        Log::error('AIEvaluationJob: failed', [
            'persona_id' => $this->persona->id,
            'ticker' => $this->signal->ticker,
            'error' => $e->getMessage(),
        ]);
    }
}
