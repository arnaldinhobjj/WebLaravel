<?php

namespace App\Ai\Skills\Recipes;

use App\Ai\Skills\Recipe;

class ExplainMyPermissions extends Recipe
{
    public function name(): string
    {
        return 'recipes.explain_my_permissions';
    }

    public function title(): string
    {
        return 'What can I do in Fort Vale?';
    }

    public function description(): string
    {
        return 'Explain, in plain words, what you\'re allowed to do and why.';
    }

    public function uses(): array
    {
        return ['people.me'];
    }

    public function instructions(array $arguments): string
    {
        return 'Explain what I\'m allowed to do in Fort Vale.

1. Use people.me.
2. In plain words, list what I can do on each page, which department and tags give me that, and anything notable I can\'t do.
3. Keep it short.';
    }
}
