<?php

namespace Database\Factories;

use App\Models\Folder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Folder>
 */
class FolderFactory extends Factory
{
    /**
     * A folder at the top of the library.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['parent_id' => fn () => Folder::libraryRoot()->id, 'name' => ucfirst(fake()->unique()->word())];
    }
}
