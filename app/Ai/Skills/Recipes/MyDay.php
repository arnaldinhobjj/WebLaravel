<?php

namespace App\Ai\Skills\Recipes;

use App\Ai\Skills\Recipe;

class MyDay extends Recipe
{
    public function name(): string
    {
        return 'recipes.my_day';
    }

    public function title(): string
    {
        return 'My day';
    }

    public function description(): string
    {
        return 'What needs your attention today: tickets, stale work and failed actions.';
    }

    public function uses(): array
    {
        return ['home.summary', 'tickets.my_queue', 'tickets.stale'];
    }

    public function instructions(array $arguments): string
    {
        return 'Give me a quick start-of-day briefing for Fort Vale.

1. Use home.summary for the numbers, tickets.my_queue for my tickets and tickets.stale (5 days) for anything forgotten.
2. Reply with: a one-line overview; the 3 most important things to do today; anything stuck; and failed action runs if there were any.
3. Keep it short and practical.';
    }
}
