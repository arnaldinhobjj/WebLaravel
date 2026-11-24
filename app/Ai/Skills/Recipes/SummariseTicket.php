<?php

namespace App\Ai\Skills\Recipes;

use App\Ai\Skills\Recipe;

class SummariseTicket extends Recipe
{
    public function name(): string
    {
        return 'recipes.summarise_ticket';
    }

    public function title(): string
    {
        return 'Summarise a ticket';
    }

    public function description(): string
    {
        return 'A short summary of a ticket: the problem, what has happened, and what\'s next.';
    }

    public function arguments(): array
    {
        return [
            'ticket' => ['description' => 'The ticket number', 'required' => true],
        ];
    }

    public function uses(): array
    {
        return ['tickets.get', 'tickets.activity'];
    }

    public function instructions(array $arguments): string
    {
        return strtr('Summarise Fort Vale ticket #{ticket} for me.

1. Read it with tickets.get and its history with tickets.activity.
2. Reply with: one line saying what the problem is; what has happened so far (who did what, briefly); its current status and who has it; and the next step, if it\'s clear.
3. Keep it under 120 words. Don\'t change anything.', ['{ticket}' => $arguments['ticket'] ?? '']);
    }
}
