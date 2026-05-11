<?php

namespace App\Jobs;

use App\Enums\TickerSource;
use App\Enums\TickerStatus;
use App\Models\Persona;
use App\Services\MarketDataService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncGainersJob implements ShouldQueue
{
    use Queueable;

    public function handle(MarketDataService $marketData): void
    {
        $gainers = $marketData->getGainers();

        if ($gainers->isEmpty()) {
            return;
        }

        Persona::where('is_active', true)->each(function (Persona $persona) use ($gainers) {
            foreach ($gainers as $gainer) {
                $persona->tickers()->firstOrCreate(
                    ['ticker' => $gainer['ticker']],
                    [
                        'status' => TickerStatus::Candidate,
                        'source' => TickerSource::GainersScan,
                    ]
                );
            }
        });
    }
}
