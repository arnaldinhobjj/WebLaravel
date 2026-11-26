<?php

namespace App\Services;

use App\Models\Material;
use App\Models\MaterialCharpyTest;
use App\Models\MaterialElement;
use App\Models\MaterialTensileTest;
use Illuminate\Support\Collection;

/**
 * The figures shown under a material's viewer: strength and impact averages,
 * weldability (carbon equivalent) and each check against the specification.
 * Expects the material's elements and tests to be loaded.
 */
class MaterialKpis
{
    /**
     * @return array{
     *     strength: array{yield_mpa: ?float, uts_mpa: ?float, yield_tensile_ratio: ?float, elongation_pct: ?float, reduction_of_area_pct: ?float},
     *     charpy: array{average_j: ?float, minimum_j: ?float, lateral_expansion_mm: ?float, shear_pct: ?float, temperature_c: ?float},
     *     weldability: array{ce_iiw: ?float, pcm: ?float},
     *     checks: list<array{label: string, passed: bool}>,
     *     passed: int,
     *     total: int,
     *     verdict: ?bool
     * }
     */
    public function for(Material $material): array
    {
        $checks = $this->checks($material);
        $passed = count(array_filter($checks, fn (array $check): bool => $check['passed']));

        return [
            'strength' => $this->strength($material->tensileTests),
            'charpy' => $this->charpy($material),
            'weldability' => $this->weldability($material->elements),
            'checks' => $checks,
            'passed' => $passed,
            'total' => count($checks),
            'verdict' => $checks === [] ? null : $passed === count($checks),
        ];
    }

    /**
     * Only whether the material meets its specification; null when there is
     * nothing to check.
     */
    public function verdict(Material $material): ?bool
    {
        return $this->for($material)['verdict'];
    }

    /**
     * @param  Collection<int, MaterialTensileTest>  $tests
     * @return array{yield_mpa: ?float, uts_mpa: ?float, yield_tensile_ratio: ?float, elongation_pct: ?float, reduction_of_area_pct: ?float}
     */
    private function strength(Collection $tests): array
    {
        $yield = $this->average($tests, 'yield_mpa');
        $uts = $this->average($tests, 'uts_mpa');

        return [
            'yield_mpa' => $yield,
            'uts_mpa' => $uts,
            'yield_tensile_ratio' => $yield !== null && $uts ? round($yield / $uts, 3) : null,
            'elongation_pct' => $this->average($tests, 'elongation_pct'),
            'reduction_of_area_pct' => $this->average($tests, 'reduction_of_area_pct'),
        ];
    }

    /**
     * Impact figures at the specified test temperature, or over every test
     * when no temperature is specified.
     *
     * @return array{average_j: ?float, minimum_j: ?float, lateral_expansion_mm: ?float, shear_pct: ?float, temperature_c: ?float}
     */
    private function charpy(Material $material): array
    {
        $tests = $this->charpyTestsAtSpecTemperature($material);

        return [
            'average_j' => $this->average($tests, 'energy_j'),
            'minimum_j' => $tests->isEmpty() ? null : (float) $tests->min('energy_j'),
            'lateral_expansion_mm' => $this->average($tests, 'lateral_expansion_mm'),
            'shear_pct' => $this->average($tests, 'shear_pct'),
            'temperature_c' => $material->charpy_temperature_c ?? ($tests->isEmpty() ? null : (float) $tests->first()->temperature_c),
        ];
    }

    /**
     * Carbon equivalents from the measured chemistry; missing elements count
     * as zero.
     *
     * CE(IIW) = C + Mn/6 + (Cr+Mo+V)/5 + (Ni+Cu)/15
     * Pcm = C + Si/30 + (Mn+Cu+Cr)/20 + Ni/60 + Mo/15 + V/10 + 5B
     *
     * @param  Collection<int, MaterialElement>  $elements
     * @return array{ce_iiw: ?float, pcm: ?float}
     */
    private function weldability(Collection $elements): array
    {
        $measured = $elements->whereNotNull('measured_pct')->mapWithKeys(fn (MaterialElement $element): array => [strtoupper($element->element) => (float) $element->measured_pct]);

        if (! $measured->has('C')) {
            return ['ce_iiw' => null, 'pcm' => null];
        }

        $percent = fn (string $element): float => (float) $measured->get($element, 0.0);

        return [
            'ce_iiw' => round($percent('C') + $percent('MN') / 6 + ($percent('CR') + $percent('MO') + $percent('V')) / 5 + ($percent('NI') + $percent('CU')) / 15, 3),
            'pcm' => round($percent('C') + $percent('SI') / 30 + ($percent('MN') + $percent('CU') + $percent('CR')) / 20 + $percent('NI') / 60 + $percent('MO') / 15 + $percent('V') / 10 + 5 * $percent('B'), 3),
        ];
    }

    /**
     * Every check against the specification that has both a limit and results.
     *
     * @return list<array{label: string, passed: bool}>
     */
    private function checks(Material $material): array
    {
        $checks = [];
        $tensile = $material->tensileTests;

        if ($tensile->isNotEmpty()) {
            if ($material->spec_min_yield_mpa !== null) {
                $checks[] = ['label' => 'Yield strength', 'passed' => $tensile->every(fn (MaterialTensileTest $test): bool => $test->yield_mpa >= $material->spec_min_yield_mpa)];
            }
            if ($material->spec_min_uts_mpa !== null || $material->spec_max_uts_mpa !== null) {
                $checks[] = ['label' => 'Tensile strength', 'passed' => $tensile->every(fn (MaterialTensileTest $test): bool => ($material->spec_min_uts_mpa === null || $test->uts_mpa >= $material->spec_min_uts_mpa)
                    && ($material->spec_max_uts_mpa === null || $test->uts_mpa <= $material->spec_max_uts_mpa))];
            }
            if ($material->spec_min_elongation_pct !== null) {
                $checks[] = ['label' => 'Elongation', 'passed' => $tensile->every(fn (MaterialTensileTest $test): bool => $test->elongation_pct >= $material->spec_min_elongation_pct)];
            }
        }

        $charpy = $this->charpyTestsAtSpecTemperature($material);
        if ($charpy->isNotEmpty()) {
            if ($material->spec_min_charpy_avg_j !== null) {
                $checks[] = ['label' => 'Charpy average', 'passed' => $this->average($charpy, 'energy_j') >= $material->spec_min_charpy_avg_j];
            }
            if ($material->spec_min_charpy_single_j !== null) {
                $checks[] = ['label' => 'Charpy single values', 'passed' => $charpy->every(fn (MaterialCharpyTest $test): bool => $test->energy_j >= $material->spec_min_charpy_single_j)];
            }
        }

        $elements = $material->elements->filter(fn (MaterialElement $element): bool => $element->meetsSpec() !== null);
        if ($elements->isNotEmpty()) {
            $checks[] = ['label' => 'Chemical composition', 'passed' => $elements->every(fn (MaterialElement $element): bool => $element->meetsSpec())];
        }

        return $checks;
    }

    /**
     * @return Collection<int, MaterialCharpyTest>
     */
    private function charpyTestsAtSpecTemperature(Material $material): Collection
    {
        if ($material->charpy_temperature_c === null) {
            return $material->charpyTests;
        }

        return $material->charpyTests->filter(fn (MaterialCharpyTest $test): bool => abs($test->temperature_c - $material->charpy_temperature_c) < 0.05)->values();
    }

    /**
     * @param  Collection<int, MaterialTensileTest|MaterialCharpyTest>  $tests
     */
    private function average(Collection $tests, string $column): ?float
    {
        $values = $tests->pluck($column)->filter(fn (mixed $value): bool => $value !== null);

        return $values->isEmpty() ? null : round((float) $values->avg(), 2);
    }
}
