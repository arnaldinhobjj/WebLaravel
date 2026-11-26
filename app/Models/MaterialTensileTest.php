<?php

namespace App\Models;

use Database\Factories\MaterialTensileTestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A tensile test of one specimen of a material.
 */
class MaterialTensileTest extends Model
{
    /** @use HasFactory<MaterialTensileTestFactory> */
    use HasFactory;

    protected $fillable = ['specimen', 'orientation', 'tested_at', 'yield_mpa', 'uts_mpa', 'elongation_pct', 'reduction_of_area_pct'];

    /**
     * Lives with its material, see config/fortvale.php.
     */
    public function getConnectionName(): ?string
    {
        return config('fortvale.connections.people');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['tested_at' => 'date', 'yield_mpa' => 'float', 'uts_mpa' => 'float', 'elongation_pct' => 'float', 'reduction_of_area_pct' => 'float'];
    }

    /**
     * @return BelongsTo<Material, $this>
     */
    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    /**
     * Whether this specimen meets the material's yield, tensile and elongation
     * limits; null when the material has none.
     */
    public function meetsSpec(): ?bool
    {
        $material = $this->material;
        $checks = array_filter([
            $material->spec_min_yield_mpa === null ? null : $this->yield_mpa >= $material->spec_min_yield_mpa,
            $material->spec_min_uts_mpa === null ? null : $this->uts_mpa >= $material->spec_min_uts_mpa,
            $material->spec_max_uts_mpa === null ? null : $this->uts_mpa <= $material->spec_max_uts_mpa,
            $material->spec_min_elongation_pct === null ? null : $this->elongation_pct >= $material->spec_min_elongation_pct,
        ], fn (?bool $check): bool => $check !== null);

        return $checks === [] ? null : ! in_array(false, $checks, true);
    }
}
