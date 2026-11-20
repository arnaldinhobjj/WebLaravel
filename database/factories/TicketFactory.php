<?php

namespace Database\Factories;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    public function definition(): array
    {
        return [
            'subject' => rtrim(fake()->sentence(6), '.'),
            'description' => fake()->paragraphs(2, true),
            'status' => TicketStatus::Open,
            'priority' => fake()->randomElement(TicketPriority::cases()),
            'category' => fake()->randomElement(array_keys(Ticket::CATEGORIES)),
            'requester_id' => User::factory(),
            'assignee_id' => null,
        ];
    }
}
