<?php

namespace App\Ai\Skills\Recipes;

use App\Ai\Skills\Recipe;

class FindDocument extends Recipe
{
    public function name(): string
    {
        return 'recipes.find_document';
    }

    public function title(): string
    {
        return 'Find a document';
    }

    public function description(): string
    {
        return 'Find a file by what it\'s about, not just its name.';
    }

    public function arguments(): array
    {
        return [
            'looking_for' => ['description' => 'What you\'re looking for', 'required' => true],
        ];
    }

    public function uses(): array
    {
        return ['files.search', 'files.recent'];
    }

    public function instructions(array $arguments): string
    {
        return strtr('Help me find a document in Fort Vale about: {looking_for}.

1. Try files.search with a few different words from my description (and synonyms).
2. If that finds nothing, look at files.recent and files.list_folder for likely folders.
3. If a text file looks right, you may read it with files.read to check.
4. Tell me the best matches with their folder, and why you think they fit.', ['{looking_for}' => $arguments['looking_for'] ?? '']);
    }
}
