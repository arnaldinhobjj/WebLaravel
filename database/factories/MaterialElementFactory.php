<?php

namespace Database\Factories;

use App\Models\Material;
use App\Models\MaterialElement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MaterialElement>
 */
class MaterialElementFactory extends Factory
{
    /**
     * Heat analysis limits of ASTM A516 Grade 70 (plate over 50 mm), mass %.
     *
     * @var array<string, array{0: ?float, 1: float}>
     */
    public const A516_GRADE_70 = [
        'C' => [null, 0.27],
        'Mn' => [0.79, 1.30],
        'P' => [null, 0.025],
        'S' => [null, 0.025],
        'Si' => [0.13, 0.45],
        'Cu' => [null, 0.40],
        'Ni' => [null, 0.40],
        'Cr' => [null, 0.30],
        'Mo' => [null, 0.12],
        'V' => [null, 0.03],
    ];

    /**
     * Carbon within its A516 Grade 70 range.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'material_id' => Material::factory(),
            'element' => 'C',
            'spec_min_pct' => null,
            'spec_max_pct' => 0.27,
            'measured_pct' => fake()->randomFloat(3, 0.16, 0.24),
        ];
    }
}
