<?php

use App\Enums\TradeAction;
use App\Jobs\AIEvaluationJob;
use App\Jobs\ExecuteTradeJob;
use App\Models\Persona;
use App\Models\PriceSnapshot;
use App\Services\AIEvaluator;
use App\Trading\TradeSignal;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    Bus::fake()->except([AIEvaluationJob::class]);
});

it('dispatches ExecuteTradeJob when AI approves the signal', function () {
    $persona = Persona::factory()->create();
    $snapshot = PriceSnapshot::factory()->forTicker('AAPL')->create(['price' => 150.0]);
    $signal = new TradeSignal('AAPL', TradeAction::Buy, 1.0, 'Algo signal', 0.6, true);

    $mockEvaluator = Mockery::mock(AIEvaluator::class);
    $mockEvaluator->shouldReceive('evaluate')
        ->once()
        ->andReturn([$signal, 'Strong momentum confirmed.']);
    $this->app->instance(AIEvaluator::class, $mockEvaluator);

    AIEvaluationJob::dispatchSync($persona, $signal, $snapshot);

    Bus::assertDispatched(ExecuteTradeJob::class, fn ($job) =>
        $job->signal->ticker === 'AAPL' &&
        $job->pricePerShare === 150.0 &&
        $job->aiRationale === 'Strong momentum confirmed.'
    );
});

it('does not dispatch ExecuteTradeJob when AI rejects the signal', function () {
    $persona = Persona::factory()->create();
    $snapshot = PriceSnapshot::factory()->forTicker('AAPL')->create(['price' => 150.0]);
    $signal = new TradeSignal('AAPL', TradeAction::Buy, 1.0, 'Algo signal', 0.6, true);

    $mockEvaluator = Mockery::mock(AIEvaluator::class);
    $mockEvaluator->shouldReceive('evaluate')->once()->andReturn(null);
    $this->app->instance(AIEvaluator::class, $mockEvaluator);

    AIEvaluationJob::dispatchSync($persona, $signal, $snapshot);

    Bus::assertNothingDispatched();
});
