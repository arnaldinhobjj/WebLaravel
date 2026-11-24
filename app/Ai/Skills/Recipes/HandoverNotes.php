<?php

namespace App\Ai\Skills\Recipes;

use App\Ai\Skills\Recipe;

class HandoverNotes extends Recipe
{
    public function name(): string
    {
        return 'recipes.handover_notes';
    }

    public function title(): string
    {
        return 'Handover notes';
    }

    public function description(): string
    {
        return 'Notes for whoever takes over your work while you\'re away.';
    }

    public function arguments(): array
    {
        return [
            'days' => ['description' => 'How long you\'ll be away (optional)', 'required' => false],
        ];
    }

    public function uses(): array
    {
        return ['tickets.my_queue', 'tickets.get'];
    }

    public function instructions(array $arguments): string
    {
        return strtr('Write handover notes for my Fort Vale work{days_text}.

1. Get my unfinished tickets with tickets.my_queue and read the important ones with tickets.get.
2. For each ticket assigned to me: what it is, where it stands, what the next step is, and who is waiting.
3. Finish with anything urgent the covering person must watch.
4. Show me the notes; offer to save them with files.save_text in My files if that\'s allowed.', ['{days_text}' => ($arguments['days'] ?? '') !== '' ? ' while I\'m away for '.$arguments['days'] : '']);
    }
}
