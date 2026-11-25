<?php

namespace Database\Factories;

use App\Enums\DashboardVisibility;
use App\Models\Dashboard;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Dashboard>
 */
class DashboardFactory extends Factory
{
    /**
     * A personal dashboard.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => ucfirst(fake()->words(2, true)),
            'visibility' => DashboardVisibility::Personal,
        ];
    }

    public function shared(): static
    {
        return $this->state(['visibility' => DashboardVisibility::Shared]);
    }
}
