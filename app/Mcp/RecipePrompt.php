<?php

namespace App\Mcp;

use App\Ai\Skills\Recipe;
use App\Ai\Skills\SkillRegistry;
use App\Models\User;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

/**
 * One Fort Vale recipe (ready-made instructions), offered as an MCP prompt.
 */
class RecipePrompt extends Prompt
{
    public function __construct(private Recipe $recipe) {}

    public function name(): string
    {
        return $this->recipe->toolName();
    }

    public function title(): string
    {
        return $this->recipe->title();
    }

    public function description(): string
    {
        return $this->recipe->description();
    }

    public function shouldRegister(): bool
    {
        $me = auth()->user();

        return $me instanceof User && app(SkillRegistry::class)->isAvailable($me, $this->recipe);
    }

    /**
     * @return array<int, Argument>
     */
    public function arguments(): array
    {
        return collect($this->recipe->arguments())
            ->map(fn (array $definition, string $name): Argument => new Argument($name, $definition['description'], $definition['required']))
            ->values()->all();
    }

    public function handle(Request $request): Response
    {
        $result = app(SkillRegistry::class)->recipe(auth()->user(), $this->recipe->name(), $request->all());

        return $result->ok ? Response::text($result->data['instructions']) : Response::error($result->summary);
    }
}
