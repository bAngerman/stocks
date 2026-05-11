<?php

namespace App\Jobs;

use App\Enums\TickerSource;
use App\Enums\TickerStatus;
use App\Models\Persona;
use App\Services\TickerDiscoveryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DiscoverTickersJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Persona $persona) {}

    public function handle(TickerDiscoveryService $service): void
    {
        $suggestions = $service->suggest($this->persona);

        foreach ($suggestions as $suggestion) {
            $this->persona->tickers()->firstOrCreate(
                ['ticker' => $suggestion['ticker']],
                [
                    'status' => TickerStatus::Candidate,
                    'source' => TickerSource::AiDiscovered,
                    'ai_rationale' => $suggestion['rationale'],
                ]
            );
        }
    }
}
