<?php

namespace App\Jobs;

use App\Models\Persona;
use App\Models\PriceSnapshot;
use App\Services\AIEvaluator;
use App\Trading\TradeSignal;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

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
        $result = $evaluator->evaluate($this->persona, $this->signal, $this->snapshot);

        if (! $result) {
            Log::info('AIEvaluationJob: signal rejected by AI', [
                'persona_id' => $this->persona->id,
                'ticker' => $this->signal->ticker,
            ]);

            return;
        }

        [$resolvedSignal, $rationale] = $result;

        ExecuteTradeJob::dispatch(
            $this->persona,
            $resolvedSignal,
            (float) $this->snapshot->price,
            $rationale,
        );
    }
}
