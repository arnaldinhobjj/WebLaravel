<?php

namespace App\Models;

use Database\Factories\MaterialCharpyTestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Charpy impact test of one specimen of a material.
 */
class MaterialCharpyTest extends Model
{
    /** @use HasFactory<MaterialCharpyTestFactory> */
    use HasFactory;

    protected $fillable = ['specimen', 'notch', 'temperature_c', 'energy_j', 'lateral_expansion_mm', 'shear_pct', 'tested_at'];

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
        return ['tested_at' => 'date', 'temperature_c' => 'float', 'energy_j' => 'float', 'lateral_expansion_mm' => 'float', 'shear_pct' => 'float'];
    }

    /**
     * @return BelongsTo<Material, $this>
     */
    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    /**
     * Whether this specimen meets the material's minimum single-value impact
     * energy; null when the material has none, or when the specimen was tested
     * at another temperature than the specification's.
     */
    public function meetsSpec(): ?bool
    {
        $minimum = $this->material->spec_min_charpy_single_j;

        if ($minimum === null || ! $this->material->isAtCharpyTemperature($this)) {
            return null;
        }

        return $this->energy_j >= $minimum;
    }
}
