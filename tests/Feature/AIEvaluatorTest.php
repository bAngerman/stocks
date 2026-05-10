<?php

use App\Enums\TradeAction;
use App\Models\Persona;
use App\Models\PriceSnapshot;
use App\Services\AIEvaluator;
use App\Trading\TradeSignal;
use Illuminate\Support\Facades\Http;

it('returns the original signal when AI approves', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [[
                'text' => json_encode(['decision' => 'approve', 'rationale' => 'Strong momentum confirmed.']),
            ]],
        ]),
    ]);

    $persona = Persona::factory()->create();
    $snapshot = PriceSnapshot::factory()->forTicker('AAPL')->create(['price' => 150.0, 'change_percent' => 1.8]);
    $signal = new TradeSignal('AAPL', TradeAction::Buy, 1.0, 'Algo signal', 0.6, true);

    [$resolvedSignal, $rationale] = app(AIEvaluator::class)->evaluate($persona, $signal, $snapshot);

    expect($resolvedSignal->ticker)->toBe('AAPL')
        ->and($resolvedSignal->action)->toBe(TradeAction::Buy)
        ->and($resolvedSignal->shares)->toBe(1.0)
        ->and($rationale)->toBe('Strong momentum confirmed.');
});

it('returns a modified signal when AI suggests different share count', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [[
                'text' => json_encode(['decision' => 'modify', 'shares' => 2.0, 'rationale' => 'Increase position size.']),
            ]],
        ]),
    ]);

    $persona = Persona::factory()->create();
    $snapshot = PriceSnapshot::factory()->forTicker('AAPL')->create(['price' => 150.0, 'change_percent' => 1.8]);
    $signal = new TradeSignal('AAPL', TradeAction::Buy, 1.0, 'Algo signal', 0.6, true);

    [$resolvedSignal, $rationale] = app(AIEvaluator::class)->evaluate($persona, $signal, $snapshot);

    expect((float) $resolvedSignal->shares)->toBe(2.0)
        ->and($rationale)->toBe('Increase position size.');
});

it('returns null when AI rejects the signal', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [[
                'text' => json_encode(['decision' => 'reject', 'rationale' => 'Insufficient volume.']),
            ]],
        ]),
    ]);

    $persona = Persona::factory()->create();
    $snapshot = PriceSnapshot::factory()->forTicker('AAPL')->create(['price' => 150.0, 'change_percent' => 1.8]);
    $signal = new TradeSignal('AAPL', TradeAction::Buy, 1.0, 'Algo signal', 0.6, true);

    $result = app(AIEvaluator::class)->evaluate($persona, $signal, $snapshot);

    expect($result)->toBeNull();
});
