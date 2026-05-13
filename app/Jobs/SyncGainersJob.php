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

    public function handle(TickerDiscoveryService $discovery, MarketDataService $marketData): void
    {
        Log::info('SyncGainersJob: starting');

        $ttl = (int) config('trading.candidate_ttl_days', 7);
        $pruned = PersonaTicker::where('status', TickerStatus::Candidate)
            ->where('created_at', '<', now()->subDays($ttl))
            ->delete();
        Log::info('SyncGainersJob: pruned stale candidates', ['count' => $pruned]);

        $personas = Persona::where('is_active', true)->get();
        if ($personas->isEmpty()) {
            Log::warning('SyncGainersJob: no active personas');

            return;
        }

        $pool = $discovery->discoverPool();
        if (empty($pool)) {
            Log::warning('SyncGainersJob: empty pool from discovery');

            return;
        }

        $assignments = $discovery->assignToPersonas($pool, $personas);
        if (empty($assignments)) {
            Log::warning('SyncGainersJob: empty assignments from discovery');

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
        $tickersAdded = 0;

        foreach ($assignments as $personaName => $tickers) {
            $persona = $personaMap->get($personaName);
            if (! $persona) {
                continue;
            }

            foreach ($tickers as $ticker) {
                if (! in_array($ticker, $validTickers)) {
                    continue;
                }

                $row = $persona->tickers()->firstOrCreate(
                    ['ticker' => $ticker],
                    [
                        'status' => TickerStatus::Candidate,
                        'source' => TickerSource::AiDiscovered,
                        'ai_rationale' => $rationales->get($ticker)['rationale'] ?? null,
                    ]
                );

                if ($row->wasRecentlyCreated) {
                    $tickersAdded++;
                }
            }
        }

        Log::info('SyncGainersJob: completed', [
            'pool_size' => count($pool),
            'valid_tickers' => count($validTickers),
            'tickers_added' => $tickersAdded,
            'personas_processed' => count($assignments),
        ]);
    }

    public function failed(Throwable $e): void
    {
        Log::error('SyncGainersJob: failed', ['error' => $e->getMessage()]);
    }
}
