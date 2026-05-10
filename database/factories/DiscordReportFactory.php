<?php

namespace Database\Factories;

use App\Models\DiscordReport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DiscordReport>
 */
class DiscordReportFactory extends Factory
{
    public function definition(): array
    {
        return [
            'period_start' => now()->subWeek(),
            'period_end' => now(),
            'payload' => ['embeds' => []],
            'posted_at' => now(),
        ];
    }
}
