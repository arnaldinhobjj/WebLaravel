<?php

namespace App\Ai\Skills\Recipes;

use App\Ai\Skills\Recipe;

class ResearchTopic extends Recipe
{
    public function name(): string
    {
        return 'recipes.research_topic';
    }

    public function title(): string
    {
        return 'Research a topic on the web';
    }

    public function description(): string
    {
        return 'Search the web, read the best sources and give a short, sourced answer.';
    }

    public function arguments(): array
    {
        return [
            'question' => ['description' => 'What you want to know', 'required' => true],
        ];
    }

    public function uses(): array
    {
        return ['web.search', 'web.read'];
    }

    public function instructions(array $arguments): string
    {
        return strtr("Research this for me: {question}\n\n1. Search the web with web.search (try two or three different phrasings if the first results are weak).\n2. Read the two to four most relevant and trustworthy results with web.read.\n3. Answer in a few short paragraphs or bullet points, and list the sources you used with their addresses.\n4. If sources disagree or are out of date, say so. Don't invent facts that aren't in the sources.", ['{question}' => $arguments['question'] ?? '']);
    }
}
