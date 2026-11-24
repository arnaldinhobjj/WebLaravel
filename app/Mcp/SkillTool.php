<?php

namespace App\Mcp;

use App\Ai\Skills\Skill;
use App\Ai\Skills\SkillRegistry;
use App\Models\User;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * One Fort Vale skill, offered as an MCP tool. Built from the neutral skill
 * registry; only offered when the person may use the skill.
 */
class SkillTool extends Tool
{
    public function __construct(private Skill $skill) {}

    public function name(): string
    {
        return $this->skill->toolName();
    }

    public function title(): string
    {
        return $this->skill->title();
    }

    public function description(): string
    {
        return $this->skill->description();
    }

    public function shouldRegister(): bool
    {
        $me = auth()->user();

        return $me instanceof User && app(SkillRegistry::class)->isAvailable($me, $this->skill);
    }

    public function handle(Request $request): Response
    {
        $result = app(SkillRegistry::class)->run(auth()->user(), $this->skill->name(), $request->all(), request()->attributes->get('ai_token_id'));

        return $result->ok
            ? Response::json(['summary' => $result->summary] + $result->data)
            : Response::error($result->summary);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name(),
            'title' => $this->title(),
            'description' => $this->description(),
            'inputSchema' => $this->skill->inputSchema(),
            'annotations' => [
                'title' => $this->title(),
                'readOnlyHint' => ! $this->skill->changesThings(),
                'destructiveHint' => $this->skill->changesThings(),
            ],
        ];
    }
}
