<?php

namespace Database\Factories;

use App\Models\Material;
use App\Models\MaterialCharpyTest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MaterialCharpyTest>
 */
class MaterialCharpyTestFactory extends Factory
{
    /**
     * A V-notch specimen at -46 °C that meets ASTM A516 Grade 70.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'material_id' => Material::factory(),
            'specimen' => fake()->bothify('C#'),
            'notch' => 'V',
            'temperature_c' => -46,
            'energy_j' => fake()->randomFloat(1, 60, 140),
            'lateral_expansion_mm' => fake()->randomFloat(2, 0.6, 1.4),
            'shear_pct' => fake()->randomFloat(0, 40, 80),
            'tested_at' => fake()->dateTimeBetween('-6 months')->format('Y-m-d'),
        ];
    }
}
