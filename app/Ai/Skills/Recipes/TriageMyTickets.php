<?php

namespace App\Ai\Skills\Recipes;

use App\Ai\Skills\Recipe;

class TriageMyTickets extends Recipe
{
    public function name(): string
    {
        return 'recipes.triage_my_tickets';
    }

    public function title(): string
    {
        return 'Triage my tickets';
    }

    public function description(): string
    {
        return 'Go through your unfinished tickets and suggest what to do first.';
    }

    public function uses(): array
    {
        return ['tickets.my_queue', 'tickets.get'];
    }

    public function instructions(array $arguments): string
    {
        return 'Help me triage my Fort Vale tickets.

1. Get my unfinished tickets with tickets.my_queue.
2. For anything urgent or high priority, read it with tickets.get.
3. Give me a short ordered list: what to do first and why (one line each), anything that looks stuck or waiting on someone, and tickets that could probably be closed.
4. Suggest status or priority changes, but don\'t make them without asking me.';
    }
}
