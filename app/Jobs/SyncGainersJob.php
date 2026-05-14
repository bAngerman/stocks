<?php

namespace App\Jobs;

use App\Enums\TickerSource;
use App\Enums\TickerStatus;
use App\Models\Persona;
use App\Models\PersonaTicker;
use App\Services\MarketDataService;
use App\Services\TickerDiscoveryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncGainersJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(TickerDiscoveryService $discovery, MarketDataService $marketData): void
    {
        $ttl = (int) config('trading.candidate_ttl_days', 7);
        PersonaTicker::where('status', TickerStatus::Candidate)
            ->where('created_at', '<', now()->subDays($ttl))
            ->delete();

        $personas = Persona::where('is_active', true)->get();
        if ($personas->isEmpty()) {
            return;
        }

        $pool = $discovery->discoverPool();
        if (empty($pool)) {
            return;
        }

        $assignments = $discovery->assignToPersonas($pool, $personas);
        if (empty($assignments)) {
            return;
        }

        $allTickers = collect($assignments)->flatten()->unique()->values()->all();
        $validTickers = collect($allTickers)->filter(function (string $ticker) use ($marketData) {
            try {
                return $marketData->getQuote($ticker)->price > 0;
            } catch (Throwable $e) {
                Log::warning('SyncGainersJob: ticker validation failed', [
                    'ticker' => $ticker,
                    'error' => $e->getMessage(),
                ]);

                return false;
            }
        })->all();

        $rationales = collect($pool)->keyBy('ticker');
        $personaMap = $personas->keyBy('name');

        foreach ($assignments as $personaName => $tickers) {
            $persona = $personaMap->get($personaName);
            if (! $persona) {
                continue;
            }

            foreach ($tickers as $ticker) {
                if (! in_array($ticker, $validTickers)) {
                    continue;
                }

                $persona->tickers()->firstOrCreate(
                    ['ticker' => $ticker],
                    [
                        'status' => TickerStatus::Candidate,
                        'source' => TickerSource::AiDiscovered,
                        'ai_rationale' => $rationales->get($ticker)['rationale'] ?? null,
                    ]
                );
            }
        }
    }

    public function failed(Throwable $e): void
    {
        Log::error('SyncGainersJob: failed', ['error' => $e->getMessage()]);
    }
}
