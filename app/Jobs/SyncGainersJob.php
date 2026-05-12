<?php

namespace App\Jobs;

use App\Enums\TickerSource;
use App\Enums\TickerStatus;
use App\Models\Persona;
use App\Services\MarketDataService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncGainersJob implements ShouldQueue
{
    use Queueable;

    public function handle(MarketDataService $marketData): void
    {
        Log::info('SyncGainersJob: starting');

        $gainers = $marketData->getGainers();

        if ($gainers->isEmpty()) {
            Log::warning('SyncGainersJob: no gainers returned from market data');

            return;
        }

        $personaCount = 0;

        Persona::where('is_active', true)->each(function (Persona $persona) use ($gainers, &$personaCount) {
            foreach ($gainers as $gainer) {
                $persona->tickers()->firstOrCreate(
                    ['ticker' => $gainer['ticker']],
                    [
                        'status' => TickerStatus::Candidate,
                        'source' => TickerSource::GainersScan,
                    ]
                );
            }
            $personaCount++;
        });

        Log::info('SyncGainersJob: completed', [
            'gainers_count' => $gainers->count(),
            'personas_synced' => $personaCount,
        ]);
    }

    public function failed(Throwable $e): void
    {
        Log::error('SyncGainersJob: failed', ['error' => $e->getMessage()]);
    }
}
