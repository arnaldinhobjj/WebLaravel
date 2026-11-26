<?php

namespace App\Models;

use Database\Factories\MaterialFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A material or part with its specification limits and test results. Its
 * blueprint, photo and 3D model are files attached to it (see
 * App\Support\Attachables), one per slot.
 */
class Material extends Model
{
    /** @use HasFactory<MaterialFactory> */
    use HasFactory;

    /** The file slots of the viewer: slot => column. */
    public const FILE_SLOTS = [
        'blueprint' => 'blueprint_file_id',
        'image' => 'image_file_id',
        'model' => 'model_file_id',
    ];

    protected $fillable = [
        'code', 'name', 'part_name', 'grade', 'heat_number', 'description',
        'spec_min_yield_mpa', 'spec_min_uts_mpa', 'spec_max_uts_mpa', 'spec_min_elongation_pct',
        'spec_min_charpy_avg_j', 'spec_min_charpy_single_j', 'charpy_temperature_c',
    ];

    /**
     * Lives with people and their files, see config/fortvale.php.
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
        return [
            'spec_min_yield_mpa' => 'float',
            'spec_min_uts_mpa' => 'float',
            'spec_max_uts_mpa' => 'float',
            'spec_min_elongation_pct' => 'float',
            'spec_min_charpy_avg_j' => 'float',
            'spec_min_charpy_single_j' => 'float',
            'charpy_temperature_c' => 'float',
        ];
    }

    /**
     * @return HasMany<MaterialElement, $this>
     */
    public function elements(): HasMany
    {
        return $this->hasMany(MaterialElement::class)->chaperone()->orderBy('id');
    }

    /**
     * @return HasMany<MaterialTensileTest, $this>
     */
    public function tensileTests(): HasMany
    {
        return $this->hasMany(MaterialTensileTest::class)->chaperone()->orderBy('specimen');
    }

    /**
     * @return HasMany<MaterialCharpyTest, $this>
     */
    public function charpyTests(): HasMany
    {
        return $this->hasMany(MaterialCharpyTest::class)->chaperone()->orderBy('specimen');
    }

    /**
     * Whether a Charpy test counts against the specification: it was tested
     * at the specified temperature, or the specification names none.
     */
    public function isAtCharpyTemperature(MaterialCharpyTest $test): bool
    {
        return $this->charpy_temperature_c === null || abs($test->temperature_c - $this->charpy_temperature_c) < 0.05;
    }

    /**
     * @return BelongsTo<StoredFile, $this>
     */
    public function blueprintFile(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'blueprint_file_id');
    }

    /**
     * @return BelongsTo<StoredFile, $this>
     */
    public function imageFile(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'image_file_id');
    }

    /**
     * @return BelongsTo<StoredFile, $this>
     */
    public function modelFile(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'model_file_id');
    }
}
