<?php

namespace App\Jobs;

use App\Enums\TradeAction;
use App\Models\DiscordReport;
use App\Models\Persona;
use App\Models\PriceSnapshot;
use App\Services\DiscordService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PostWeeklyReportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120, 300];

    public function handle(DiscordService $discord): void
    {
        $periodEnd = now();
        $periodStart = $periodEnd->copy()->subWeek();

        $personas = Persona::where('is_active', true)->with(['trades', 'openPositions'])->get();

        $fields = $personas->map(fn (Persona $persona) => $this->buildPersonaField($persona, $periodStart, $periodEnd));

        $embeds = [[
            'title' => '📊 Weekly Trading Report',
            'description' => 'Period: '.$periodStart->format('M j').' – '.$periodEnd->format('M j, Y'),
            'fields' => $fields->toArray(),
            'color' => 3066993,
            'timestamp' => $periodEnd->toIso8601String(),
        ]];

        $discord->postMessage($embeds);

        DiscordReport::create([
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'payload' => ['embeds' => $embeds],
            'posted_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function buildPersonaField(Persona $persona, Carbon $periodStart, Carbon $periodEnd): array
    {
        $weeklyTrades = $persona->trades()
            ->whereBetween('executed_at', [$periodStart, $periodEnd])
            ->get();

        $buyCount = $weeklyTrades->filter(fn ($t) => $t->action === TradeAction::Buy)->count();
        $sellCount = $weeklyTrades->filter(fn ($t) => $t->action === TradeAction::Sell)->count();

        $positionLines = $persona->openPositions->map(function ($position) {
            $snapshot = PriceSnapshot::where('ticker', $position->ticker)
                ->latest('fetched_at')
                ->first();

            if (! $snapshot) {
                return "• {$position->ticker}: {$position->shares} shares (no price data)";
            }

            $currentValue = (float) $position->shares * (float) $snapshot->price;
            $unrealisedPnl = ((float) $snapshot->price - (float) $position->average_cost) * (float) $position->shares;
            $sign = $unrealisedPnl >= 0 ? '+' : '';

            return "• {$position->ticker}: {$position->shares} × \${$snapshot->price} = \${$currentValue} ({$sign}".round($unrealisedPnl, 2).')';
        })->join("\n");

        $lines = [
            "**Cash:** \${$persona->cash_balance}",
            "**Trades this week:** {$weeklyTrades->count()} ({$buyCount} buys, {$sellCount} sells)",
        ];

        if ($positionLines) {
            $lines[] = '';
            $lines[] = '**Open Positions:**';
            $lines[] = $positionLines;
        }

        return [
            'name' => '🤖 '.$persona->name,
            'value' => implode("\n", $lines),
            'inline' => false,
        ];
    }
}
