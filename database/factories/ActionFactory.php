<?php

namespace Database\Factories;

use App\Enums\ActionType;
use App\Models\Action;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Action>
 */
class ActionFactory extends Factory
{
    /**
     * A link action that opens a page inside the app.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => ucfirst(fake()->words(3, true)),
            'description' => fake()->sentence(),
            'type' => ActionType::Link,
            'url' => '/ticket?priority=urgent',
            'show_on_home' => true,
        ];
    }

    /**
     * An action that opens the new ticket form from a template.
     */
    public function ticketTemplate(): static
    {
        return $this->state(fn (): array => [
            'type' => ActionType::Ticket,
            'url' => null,
            'ticket_subject' => 'Badge reader fault',
            'ticket_category' => 'facilities',
            'ticket_priority' => 'high',
            'ticket_description' => 'Which reader, and what happens when you badge?',
        ]);
    }
}
