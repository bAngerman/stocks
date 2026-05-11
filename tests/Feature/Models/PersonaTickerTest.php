<?php

use App\Enums\TickerSource;
use App\Enums\TickerStatus;
use App\Models\Persona;
use App\Models\PersonaTicker;
use Illuminate\Database\QueryException;

it('can create a persona ticker with correct casts', function () {
    $persona = Persona::factory()->create();

    $ticker = PersonaTicker::create([
        'persona_id' => $persona->id,
        'ticker' => 'AAPL',
        'status' => TickerStatus::Active,
        'source' => TickerSource::Initial,
    ]);

    expect($ticker->ticker)->toBe('AAPL')
        ->and($ticker->status)->toBe(TickerStatus::Active)
        ->and($ticker->source)->toBe(TickerSource::Initial)
        ->and($ticker->evaluations_without_signal)->toBe(0)
        ->and($ticker->promoted_at)->toBeNull();
});

it('active tickers relation returns only active tickers', function () {
    $persona = Persona::factory()->create();
    $persona->tickers()->create(['ticker' => 'AAPL', 'status' => TickerStatus::Active, 'source' => TickerSource::Initial]);
    $persona->tickers()->create(['ticker' => 'TSLA', 'status' => TickerStatus::Candidate, 'source' => TickerSource::AiDiscovered, 'ai_rationale' => 'AI pick']);

    expect($persona->activeTickers)->toHaveCount(1)
        ->and($persona->activeTickers->first()->ticker)->toBe('AAPL');
});

it('candidate tickers relation returns only candidate tickers', function () {
    $persona = Persona::factory()->create();
    $persona->tickers()->create(['ticker' => 'AAPL', 'status' => TickerStatus::Active, 'source' => TickerSource::Initial]);
    $persona->tickers()->create(['ticker' => 'TSLA', 'status' => TickerStatus::Candidate, 'source' => TickerSource::AiDiscovered, 'ai_rationale' => 'AI pick']);

    expect($persona->candidateTickers)->toHaveCount(1)
        ->and($persona->candidateTickers->first()->ticker)->toBe('TSLA');
});

it('tickers relation returns all tickers regardless of status', function () {
    $persona = Persona::factory()->create();
    $persona->tickers()->create(['ticker' => 'AAPL', 'status' => TickerStatus::Active, 'source' => TickerSource::Initial]);
    $persona->tickers()->create(['ticker' => 'TSLA', 'status' => TickerStatus::Candidate, 'source' => TickerSource::AiDiscovered, 'ai_rationale' => 'AI pick']);

    expect($persona->tickers)->toHaveCount(2);
});

it('enforces unique ticker per persona', function () {
    $persona = Persona::factory()->create();
    $persona->tickers()->create(['ticker' => 'AAPL', 'status' => TickerStatus::Active, 'source' => TickerSource::Initial]);

    expect(fn () => $persona->tickers()->create(['ticker' => 'AAPL', 'status' => TickerStatus::Candidate, 'source' => TickerSource::AiDiscovered]))
        ->toThrow(QueryException::class);
});
