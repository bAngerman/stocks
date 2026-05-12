<?php

namespace App\Jobs;

use App\Enums\StrategyType;
use App\Enums\TradeAction;
use App\Models\DiscordReport;
use App\Models\Persona;
use App\Models\PersonaPortfolioSnapshot;
use App\Models\PriceSnapshot;
use App\Services\DiscordService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class PostWeeklyReportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120, 300];

    private const COLOR_GREEN = 5746727;

    private const COLOR_RED = 15548997;

    private const COLOR_BLUE = 3447003;

    private const COLOR_GOLD = 16766720;

    private const RANK_MEDALS = ['🥇', '🥈', '🥉', '4️⃣', '5️⃣', '6️⃣', '7️⃣', '8️⃣'];

    public function handle(DiscordService $discord): void
    {
        Log::info('PostWeeklyReportJob: starting');

        $periodEnd = now();
        $periodStart = $periodEnd->copy()->subWeek();

        $personas = Persona::where('is_active', true)
            ->with([
                'openPositions',
                'trades' => fn ($q) => $q->whereBetween('executed_at', [$periodStart, $periodEnd]),
            ])
            ->get();

        $tickers = $personas->flatMap(fn ($p) => $p->openPositions->pluck('ticker'))->unique()->values();

        $latestSnapshots = $tickers->isEmpty()
            ? collect()
            : PriceSnapshot::whereIn('ticker', $tickers)
                ->where('fetched_at', '>=', now()->subDay())
                ->orderByDesc('fetched_at')
                ->get()
                ->unique('ticker')
                ->keyBy('ticker');

        $totalValues = $personas->mapWithKeys(fn (Persona $persona) => [
            $persona->id => $this->computeTotalValue($persona, $latestSnapshots),
        ]);

        $personaIds = $personas->pluck('id');

        $latestSnapshotIds = PersonaPortfolioSnapshot::query()
            ->selectRaw('MAX(id) as id')
            ->whereIn('persona_id', $personaIds)
            ->groupBy('persona_id')
            ->pluck('id');

        $previousSnapshots = PersonaPortfolioSnapshot::whereIn('id', $latestSnapshotIds)
            ->get()
            ->keyBy('persona_id');

        $ranked = $personas->sortByDesc(fn (Persona $p) => $totalValues[$p->id])->values();

        $embeds = [$this->buildHeaderEmbed($ranked, $totalValues, $previousSnapshots, $periodStart, $periodEnd)];

        foreach ($ranked as $rank => $persona) {
            $embeds[] = $this->buildPersonaEmbed(
                $persona,
                $rank + 1,
                $totalValues[$persona->id],
                $previousSnapshots->get($persona->id),
                $latestSnapshots,
            );
        }

        $discord->postMessage($embeds);

        $now = now();

        DB::transaction(function () use ($personas, $totalValues, $now, $periodStart, $periodEnd, $embeds) {
            PersonaPortfolioSnapshot::insert(
                $personas->map(fn (Persona $persona) => [
                    'persona_id' => $persona->id,
                    'total_value' => $totalValues[$persona->id],
                    'cash_balance' => $persona->cash_balance,
                    'snapshotted_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->toArray()
            );

            DiscordReport::create([
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'payload' => ['embeds' => $embeds],
                'posted_at' => $now,
            ]);
        });

        Log::info('PostWeeklyReportJob: completed', [
            'personas_count' => $personas->count(),
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
        ]);
    }

    public function failed(Throwable $e): void
    {
        Log::error('PostWeeklyReportJob: failed', ['error' => $e->getMessage()]);
    }

    private function computeTotalValue(Persona $persona, Collection $latestSnapshots): float
    {
        $positionsValue = $persona->openPositions->sum(function ($position) use ($latestSnapshots) {
            $snapshot = $latestSnapshots->get($position->ticker);
            $price = $snapshot ? (float) $snapshot->price : (float) $position->average_cost;

            return (float) $position->shares * $price;
        });

        return (float) $persona->cash_balance + $positionsValue;
    }

    /** @param Collection<int, Persona> $ranked */
    private function buildHeaderEmbed(
        Collection $ranked,
        Collection $totalValues,
        Collection $previousSnapshots,
        Carbon $periodStart,
        Carbon $periodEnd,
    ): array {
        $hasHistory = $previousSnapshots->isNotEmpty();

        $rows = $ranked->map(function (Persona $persona, int $index) use ($totalValues, $previousSnapshots, $hasHistory) {
            $medal = self::RANK_MEDALS[$index] ?? ($index + 1).'️⃣';
            $name = str_pad($persona->name, 24);
            $total = $totalValues[$persona->id];
            $valueStr = '$'.number_format($total, 0);

            if ($hasHistory && $previousSnapshots->has($persona->id)) {
                $prev = (float) $previousSnapshots[$persona->id]->total_value;
                $delta = $total - $prev;
                $pct = $prev > 0 ? ($delta / $prev) * 100 : 0.0;
                $sign = $delta >= 0 ? '+' : '-';
                $wowStr = "  {$sign}\$".number_format(abs($delta), 0)."  ({$sign}".number_format(abs($pct), 1).'%)';

                return "{$medal} {$name} {$valueStr}{$wowStr}";
            }

            return "{$medal} {$name} {$valueStr}";
        })->join("\n");

        $description = 'Period: '.$periodStart->format('M j').' – '.$periodEnd->format('M j, Y')."\n\n**🏆 LEADERBOARD**\n".$rows;

        if ($hasHistory) {
            $withWoW = $ranked
                ->filter(fn (Persona $p) => $previousSnapshots->has($p->id))
                ->map(function (Persona $p) use ($totalValues, $previousSnapshots) {
                    $prev = (float) $previousSnapshots[$p->id]->total_value;
                    $pct = $prev > 0 ? (($totalValues[$p->id] - $prev) / $prev) * 100 : 0.0;

                    return ['name' => $p->name, 'pct' => $pct];
                });

            if ($withWoW->isNotEmpty()) {
                $best = $withWoW->sortByDesc('pct')->first();
                $worst = $withWoW->sortBy('pct')->first();
                $bestSign = $best['pct'] >= 0 ? '+' : '';
                $worstSign = $worst['pct'] >= 0 ? '+' : '';
                $description .= "\n\n📈 **Best this week:** {$best['name']}  ({$bestSign}".number_format($best['pct'], 1).'%)';
                $description .= "\n📉 **Worst this week:** {$worst['name']}  ({$worstSign}".number_format($worst['pct'], 1).'%)';
            }
        }

        return [
            'title' => '📊 Weekly Trading Report',
            'description' => $description,
            'color' => self::COLOR_GOLD,
            'timestamp' => $periodEnd->toIso8601String(),
        ];
    }

    private function buildPersonaEmbed(
        Persona $persona,
        int $rank,
        float $totalValue,
        ?PersonaPortfolioSnapshot $previousSnapshot,
        Collection $latestSnapshots,
    ): array {
        $medal = self::RANK_MEDALS[$rank - 1] ?? $rank.'️⃣';

        $wowValue = '—';
        $color = self::COLOR_BLUE;

        if ($previousSnapshot !== null) {
            $prev = (float) $previousSnapshot->total_value;
            $delta = $totalValue - $prev;
            $pct = $prev > 0 ? ($delta / $prev) * 100 : 0.0;
            $sign = $delta >= 0 ? '+' : '-';
            $wowValue = "{$sign}\$".number_format(abs($delta), 2).' ('.$sign.number_format(abs($pct), 1).'%)';
            $color = match (true) {
                $delta > 0 => self::COLOR_GREEN,
                $delta < 0 => self::COLOR_RED,
                default => self::COLOR_BLUE,
            };
        }

        $strategyLabel = match ($persona->strategy_type) {
            StrategyType::Momentum => '📈 Momentum',
            StrategyType::MeanReversion => '🔄 Mean Reversion',
            default => $persona->strategy_type->value,
        };

        $positionsValue = $persona->openPositions->isEmpty()
            ? 'No open positions'
            : $persona->openPositions->map(function ($position) use ($latestSnapshots) {
                $snapshot = $latestSnapshots->get($position->ticker);

                if ($snapshot === null) {
                    return "• {$position->ticker}  {$position->shares} shares (no price data)";
                }

                $shares = rtrim(rtrim(number_format((float) $position->shares, 4), '0'), '.');
                $price = number_format((float) $snapshot->price, 2);
                $value = number_format((float) $position->shares * (float) $snapshot->price, 2);
                $pnl = ((float) $snapshot->price - (float) $position->average_cost) * (float) $position->shares;
                $pnlSign = $pnl >= 0 ? '+' : '';
                $dot = $pnl >= 0 ? '🟢' : '🔴';

                return "• {$position->ticker}  {$shares} × \${$price} = \${$value}    ({$pnlSign}".number_format($pnl, 2).") {$dot}";
            })->join("\n");

        $tradesValue = $persona->trades->isEmpty()
            ? 'No trades this week'
            : $persona->trades->count().'  ('
                .$persona->trades->filter(fn ($t) => $t->action === TradeAction::Buy)->count().' buys · '
                .$persona->trades->filter(fn ($t) => $t->action === TradeAction::Sell)->count().' sells)';

        return [
            'title' => "{$medal} {$persona->name}",
            'description' => $strategyLabel,
            'color' => $color,
            'fields' => [
                ['name' => 'Total Value', 'value' => '$'.number_format($totalValue, 2), 'inline' => true],
                ['name' => 'Week Change', 'value' => $wowValue, 'inline' => true],
                ['name' => 'Cash', 'value' => '$'.number_format((float) $persona->cash_balance, 2), 'inline' => true],
                ['name' => 'Open Positions', 'value' => $positionsValue, 'inline' => false],
                ['name' => 'Weekly Trades', 'value' => $tradesValue, 'inline' => false],
            ],
        ];
    }
}
