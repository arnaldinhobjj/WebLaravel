<?php

namespace App\Mcp\Servers;

use App\Ai\Skills\SkillRegistry;
use App\Mcp\RecipePrompt;
use App\Mcp\SkillTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

/**
 * Fort Vale for any MCP app. Its tools and prompts come from the neutral
 * skill registry (App\Ai\Skills), so every skill is written once. The token
 * decides who the AI acts as; it can do exactly what that person can.
 */
#[Name('Fort Vale')]
#[Version('2.0.0')]
#[Instructions('Fort Vale is an operations workspace with tickets, files, email and actions. You act as the person whose token you use, with their permissions. Look things up before acting. Tools marked destructive create, change, send or delete things: show the person what you will do and get their OK first. Prompts are ready-made recipes for common jobs. If a tool returns an error, tell the person what went wrong; never guess the answer instead.')]
class FortValeServer extends Server
{
    /** All skills in one page: some AI apps never ask for a second one. */
    public int $defaultPaginationLength = 100;

    public int $maxPaginationLength = 100;

    protected function boot(): void
    {
        $registry = app(SkillRegistry::class);
        $this->tools = array_values(array_map(fn ($skill) => new SkillTool($skill), $registry->abilities()));
        $this->prompts = array_values(array_map(fn ($recipe) => new RecipePrompt($recipe), $registry->recipes()));
    }
}
