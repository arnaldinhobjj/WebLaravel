<?php

namespace Database\Factories;

use App\Models\ActionRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActionRun>
 */
class ActionRunFactory extends Factory
{
    /**
     * A successful Run-button run of a shared action.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'action_key' => 'shared:display-sales',
            'action_name' => 'Display sales',
            'source' => 'shared',
            'user_id' => User::factory(),
            'trigger' => 'run',
            'status' => ActionRun::SUCCESS,
            'steps' => [['name' => 'message/show', 'uses' => 'message/show', 'status' => 'success', 'output' => [], 'ms' => 1]],
            'started_at' => now(),
            'finished_at' => now(),
        ];
    }
}
