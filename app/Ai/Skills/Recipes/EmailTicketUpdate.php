<?php

namespace App\Ai\Skills\Recipes;

use App\Ai\Skills\Recipe;

class EmailTicketUpdate extends Recipe
{
    public function name(): string
    {
        return 'recipes.email_ticket_update';
    }

    public function title(): string
    {
        return 'Email an update about a ticket';
    }

    public function description(): string
    {
        return 'Write and send a short status update about a ticket.';
    }

    public function arguments(): array
    {
        return [
            'ticket' => ['description' => 'The ticket number', 'required' => true],
            'to' => ['description' => 'Who to send it to (usernames or addresses)', 'required' => true],
        ];
    }

    public function uses(): array
    {
        return ['tickets.get', 'email.send'];
    }

    public function instructions(array $arguments): string
    {
        return strtr('Send a short update about Fort Vale ticket #{ticket} to: {to}.

1. Read the ticket with tickets.get.
2. Write a short, clear email: what the issue is, where it stands now, and what happens next. Include the ticket link.
3. Show me the email and only send it with email.send after I confirm.', ['{ticket}' => $arguments['ticket'] ?? '', '{to}' => $arguments['to'] ?? '']);
    }
}
