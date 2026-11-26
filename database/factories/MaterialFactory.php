<?php

namespace Database\Factories;

use App\Models\Material;
use App\Models\MaterialCharpyTest;
use App\Models\MaterialElement;
use App\Models\MaterialTensileTest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Material>
 */
class MaterialFactory extends Factory
{
    /**
     * A carbon steel plate to ASTM A516 Grade 70, without test results.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'MAT-'.fake()->unique()->numerify('####'),
            'name' => 'Carbon steel plate',
            'part_name' => 'Pressure vessel shell plate',
            'grade' => 'ASTM A516 Gr.70',
            'heat_number' => fake()->bothify('H##?####'),
            'description' => 'Normalised plate for moderate- and lower-temperature pressure vessels.',
            'spec_min_yield_mpa' => 260,
            'spec_min_uts_mpa' => 485,
            'spec_max_uts_mpa' => 620,
            'spec_min_elongation_pct' => 21,
            'spec_min_charpy_avg_j' => 27,
            'spec_min_charpy_single_j' => 20,
            'charpy_temperature_c' => -46,
        ];
    }

    /**
     * With a chemical analysis, three tensile tests and three Charpy tests
     * that all meet the specification.
     */
    public function withTests(): static
    {
        return $this->afterCreating(function (Material $material): void {
            foreach (MaterialElementFactory::A516_GRADE_70 as $element => [$min, $max]) {
                MaterialElement::factory()->for($material)->create([
                    'element' => $element,
                    'spec_min_pct' => $min,
                    'spec_max_pct' => $max,
                    'measured_pct' => round((($min ?? 0) + $max) / 2, 3),
                ]);
            }
            foreach (['T1', 'T2', 'T3'] as $specimen) {
                MaterialTensileTest::factory()->for($material)->create(['specimen' => $specimen]);
            }
            foreach (['C1', 'C2', 'C3'] as $specimen) {
                MaterialCharpyTest::factory()->for($material)->create(['specimen' => $specimen, 'temperature_c' => $material->charpy_temperature_c ?? -46]);
            }
        });
    }
}
