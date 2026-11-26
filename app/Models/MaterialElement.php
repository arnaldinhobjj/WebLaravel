<?php

namespace App\Models;

use Database\Factories\MaterialElementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One element of a material: the specified range (composition) and the measured value (chemical analysis), in mass %.
 */
class MaterialElement extends Model
{
    /** @use HasFactory<MaterialElementFactory> */
    use HasFactory;

    protected $fillable = ['element', 'spec_min_pct', 'spec_max_pct', 'measured_pct'];

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
        return ['spec_min_pct' => 'float', 'spec_max_pct' => 'float', 'measured_pct' => 'float'];
    }

    /**
     * @return BelongsTo<Material, $this>
     */
    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    /**
     * Whether the measured value is within the specified range; null when
     * there is nothing to compare.
     */
    public function meetsSpec(): ?bool
    {
        if ($this->measured_pct === null || ($this->spec_min_pct === null && $this->spec_max_pct === null)) {
            return null;
        }

        return ($this->spec_min_pct === null || $this->measured_pct >= $this->spec_min_pct)
            && ($this->spec_max_pct === null || $this->measured_pct <= $this->spec_max_pct);
    }
}
