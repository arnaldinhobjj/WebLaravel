<?php

namespace Database\Factories;

use App\Models\Tag;
use App\Support\PagePermissions;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tag>
 */
class TagFactory extends Factory
{
    /**
     * A tag that grants nothing.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['name' => fake()->unique()->word(), 'description' => null, 'permissions' => []];
    }

    /**
     * A tag that grants these "page.ability" permissions (View is added where needed).
     */
    public function granting(string ...$permissions): static
    {
        return $this->state(['permissions' => PagePermissions::normalize($permissions)]);
    }
}
