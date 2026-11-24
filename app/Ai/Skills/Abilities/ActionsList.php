<?php

namespace App\Ai\Skills\Abilities;

use App\Ai\Skills\Skill;
use App\Ai\Skills\SkillResult;
use App\Models\Action;
use App\Models\User;
use App\Services\Workflows\Workflow;
use App\Services\Workflows\WorkflowRepository;

class ActionsList extends Skill
{
    public function name(): string
    {
        return 'actions.list';
    }

    public function title(): string
    {
        return 'List actions I can run';
    }

    public function description(): string
    {
        return 'The Fort Vale actions the person may run from a Run button, with what they do and their inputs. Run one with actions.run and its key.';
    }

    public function allows(User $me): bool
    {
        return $me->hasPermission('shared_actions.run') || $me->hasPermission('actions.run');
    }

    public function run(User $me, array $input): SkillResult
    {
        $actions = array_map(fn (Workflow $workflow): array => [
            'key' => $workflow->key,
            'name' => $workflow->name,
            'description' => $workflow->description,
            'shared' => $workflow->isShared(),
            'inputs' => $workflow->inputs(),
        ], self::runnable($me));

        return SkillResult::ok(count($actions).' actions you can run.', ['actions' => $actions]);
    }

    /**
     * Run-button actions this person may run: shared ones and their own.
     *
     * @return list<Workflow>
     */
    public static function runnable(User $me): array
    {
        $shared = $me->hasPermission('shared_actions.run')
            ? app(WorkflowRepository::class)->shared()->filter(fn (Workflow $workflow): bool => $workflow->canRunManually())->values()->all()
            : [];
        $personal = $me->hasPermission('actions.run')
            ? $me->actions()->get()->map(fn (Action $action): Workflow => $action->workflow())->filter(fn (Workflow $workflow): bool => $workflow->canRunManually())->values()->all()
            : [];

        return [...$shared, ...$personal];
    }
}
