<?php

namespace App\Ai\Skills\Recipes;

use App\Ai\Skills\Recipe;

class WriteAction extends Recipe
{
    public function name(): string
    {
        return 'recipes.write_action';
    }

    public function title(): string
    {
        return 'Write an action';
    }

    public function description(): string
    {
        return 'Write a Fort Vale action (YAML) from a plain description, checked before you use it.';
    }

    public function arguments(): array
    {
        return [
            'what' => ['description' => 'What the action should do and what should start it', 'required' => true],
        ];
    }

    public function uses(): array
    {
        return ['actions.validate'];
    }

    public function instructions(array $arguments): string
    {
        return strtr('Write a Fort Vale action that does this: {what}

Fort Vale actions are YAML: name, on (run with optional inputs and show_on_home, ticket with types and if, or schedule with cron), and steps using ticket/create, ticket/update, ticket/reply, http/request, email/send, file/save, ai/report (asks the AI for a report in the background, saved in My files), message/show, page/open or ticket/open-form. Values use ${{ inputs.x }}, ${{ ticket.id }}, ${{ actor.name }}, ${{ steps.<id>.<output> }}.

1. Write the YAML.
2. Check it with actions.validate and fix every mistake until it\'s valid.
3. Show me the final YAML and explain in two or three lines what it does and anything I must set up. Don\'t run it.', ['{what}' => $arguments['what'] ?? '']);
    }
}
