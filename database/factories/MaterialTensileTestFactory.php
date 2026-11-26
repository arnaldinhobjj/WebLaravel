<?php

namespace Database\Factories;

use App\Models\Material;
use App\Models\MaterialTensileTest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MaterialTensileTest>
 */
class MaterialTensileTestFactory extends Factory
{
    /**
     * A specimen that meets ASTM A516 Grade 70.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'material_id' => Material::factory(),
            'specimen' => fake()->bothify('T#'),
            'orientation' => 'Transverse',
            'tested_at' => fake()->dateTimeBetween('-6 months')->format('Y-m-d'),
            'yield_mpa' => fake()->randomFloat(1, 300, 380),
            'uts_mpa' => fake()->randomFloat(1, 510, 580),
            'elongation_pct' => fake()->randomFloat(1, 24, 30),
            'reduction_of_area_pct' => fake()->randomFloat(1, 55, 68),
        ];
    }
}
