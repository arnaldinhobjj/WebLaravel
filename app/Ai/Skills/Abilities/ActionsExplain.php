<?php

namespace App\Ai\Skills\Abilities;

use App\Ai\Skills\Skill;
use App\Ai\Skills\SkillFailed;
use App\Ai\Skills\SkillResult;
use App\Models\User;
use App\Services\Workflows\Workflow;
use App\Services\Workflows\WorkflowRepository;

class ActionsExplain extends Skill
{
    public function name(): string
    {
        return 'actions.explain';
    }

    public function title(): string
    {
        return 'Explain an action';
    }

    public function description(): string
    {
        return 'Show an action\'s YAML, what starts it and its steps, by key ("shared:<name>" or "personal:<id>"), so you can explain or improve it.';
    }

    public function properties(): array
    {
        return ['key' => ['type' => 'string', 'maxLength' => 120]];
    }

    public function required(): array
    {
        return ['key'];
    }

    public function allows(User $me): bool
    {
        return $me->hasPermission('shared_actions.view') || $me->hasPermission('actions.view');
    }

    public function run(User $me, array $input): SkillResult
    {
        $key = $input['key'];
        $workflow = match (true) {
            str_starts_with($key, 'shared:') && $me->hasPermission('shared_actions.view') => app(WorkflowRepository::class)->findShared(substr($key, 7)),
            str_starts_with($key, 'personal:') && $me->hasPermission('actions.view') => $me->actions()->whereKey((int) substr($key, 9))->first()?->workflow(),
            default => null,
        };
        if (! $workflow instanceof Workflow) {
            throw new SkillFailed("No action “{$key}” that you can see.");
        }

        return SkillResult::ok("{$workflow->name}: ".implode(', ', $workflow->triggers()).', '.count($workflow->steps).' steps.', [
            'key' => $workflow->key,
            'name' => $workflow->name,
            'triggers' => $workflow->triggers(),
            'steps' => array_map(fn (array $step): array => ['name' => $step['name'], 'uses' => $step['uses']], $workflow->steps),
            'yaml' => $workflow->yaml,
        ]);
    }
}
