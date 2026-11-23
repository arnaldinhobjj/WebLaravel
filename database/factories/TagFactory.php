<?php

namespace Database\Factories;

use App\Enums\Permission;
use App\Models\Tag;
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

    public function granting(Permission ...$permissions): static
    {
        return $this->state(['permissions' => array_map(fn (Permission $permission) => $permission->value, $permissions)]);
    }
}
