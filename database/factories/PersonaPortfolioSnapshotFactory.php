<?php

namespace Database\Factories;

use App\Models\Persona;
use App\Models\PersonaPortfolioSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PersonaPortfolioSnapshot>
 */
class PersonaPortfolioSnapshotFactory extends Factory
{
    public function definition(): array
    {
        return [
            'persona_id' => Persona::factory(),
            'total_value' => $this->faker->randomFloat(2, 8000, 12000),
            'cash_balance' => $this->faker->randomFloat(2, 2000, 8000),
            'snapshotted_at' => now()->subWeek(),
        ];
    }
}
