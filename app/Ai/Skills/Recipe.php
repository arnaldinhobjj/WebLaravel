<?php

namespace App\Ai\Skills;

/**
 * Ready-made instructions: a named recipe any AI can follow, using the
 * abilities it lists. Offered as MCP prompts, over HTTP, and as one-click
 * entries in the /ai chat.
 */
abstract class Recipe
{
    /** Readable id, e.g. "recipes.summarise_ticket". */
    abstract public function name(): string;

    abstract public function title(): string;

    abstract public function description(): string;

    /**
     * @return array<string, array{description: string, required: bool}>
     */
    public function arguments(): array
    {
        return [];
    }

    /**
     * Abilities the recipe relies on; it's offered only when all are available.
     *
     * @return list<string>
     */
    abstract public function uses(): array;

    /**
     * The instructions, with the arguments filled in.
     *
     * @param  array<string, string>  $arguments
     */
    abstract public function instructions(array $arguments): string;

    public function toolName(): string
    {
        return str_replace('.', '_', $this->name());
    }
}
