<?php

namespace App\Ai\Skills\Recipes;

use App\Ai\Skills\Recipe;

class DraftReply extends Recipe
{
    public function name(): string
    {
        return 'recipes.draft_reply';
    }

    public function title(): string
    {
        return 'Draft a reply';
    }

    public function description(): string
    {
        return 'Draft a reply to a ticket for you to check before anything is posted.';
    }

    public function arguments(): array
    {
        return [
            'ticket' => ['description' => 'The ticket number', 'required' => true],
            'tone' => ['description' => 'Friendly, formal or short (optional)', 'required' => false],
        ];
    }

    public function uses(): array
    {
        return ['tickets.get', 'tickets.reply'];
    }

    public function instructions(array $arguments): string
    {
        return strtr('Draft a reply to Fort Vale ticket #{ticket}.

1. Read the ticket and its replies with tickets.get.
2. Write a reply that answers the latest message, in a {tone} tone (friendly if none was given). Don\'t promise things that aren\'t in the ticket.
3. Show me the draft and ask whether to post it. Only post it with tickets.reply after I say yes.', ['{ticket}' => $arguments['ticket'] ?? '', '{tone}' => ($arguments['tone'] ?? '') ?: 'friendly']);
    }
}
