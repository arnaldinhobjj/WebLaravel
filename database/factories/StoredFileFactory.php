<?php

namespace Database\Factories;

use App\Models\StoredFile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Files are created through App\Services\Files\FileStore, which stores their
 * content; this factory only exists for the HasFactory convention.
 *
 * @extends Factory<StoredFile>
 */
class StoredFileFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['name' => fake()->word().'.txt', 'size' => 0, 'mime' => 'text/plain'];
    }
}
