<?php

namespace App\Ai\Skills\Recipes;

use App\Ai\Skills\Recipe;

class WeeklyTicketReport extends Recipe
{
    public function name(): string
    {
        return 'recipes.weekly_ticket_report';
    }

    public function title(): string
    {
        return 'Weekly ticket report';
    }

    public function description(): string
    {
        return 'A report of the tickets you can see, optionally saved as a file.';
    }

    public function arguments(): array
    {
        return [
            'save_to' => ['description' => 'A folder to save the report in, e.g. Library/Reports (optional)', 'required' => false],
        ];
    }

    public function uses(): array
    {
        return ['tickets.search', 'tickets.stale'];
    }

    public function instructions(array $arguments): string
    {
        return strtr('Write a weekly report of the Fort Vale tickets I can see.

1. Use tickets.search (status active, and also resolved and closed) and tickets.stale (7 days).
2. Write a short report in Markdown: totals by status and priority, what got resolved, what\'s urgent, what\'s stuck, and anything that stands out.
3. If a folder was given ({save_to}), save the report there with files.save_text as weekly-ticket-report.md, after showing it to me. Otherwise just show it.', ['{save_to}' => ($arguments['save_to'] ?? '') ?: 'none given']);
    }
}
