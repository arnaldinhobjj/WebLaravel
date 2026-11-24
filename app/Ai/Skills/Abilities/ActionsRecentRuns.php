<?php

namespace App\Ai\Skills\Abilities;

use App\Ai\Skills\Skill;
use App\Ai\Skills\SkillResult;
use App\Models\ActionRun;
use App\Models\User;

class ActionsRecentRuns extends Skill
{
    public function name(): string
    {
        return 'actions.recent_runs';
    }

    public function title(): string
    {
        return 'Recent action runs';
    }

    public function description(): string
    {
        return 'Recent runs the person started or of their own actions: whether they worked, what started them, and the step that failed.';
    }

    public function properties(): array
    {
        return ['failed_only' => ['type' => 'boolean']];
    }

    public function allows(User $me): bool
    {
        return $me->hasPermission('actions.view') || $me->hasPermission('shared_actions.view');
    }

    public function run(User $me, array $input): SkillResult
    {
        $runs = ActionRun::visibleTo($me)->latest('started_at')->latest('id')
            ->when(! empty($input['failed_only']), fn ($q) => $q->where('status', ActionRun::FAILED))
            ->limit(20)->get()
            ->map(fn (ActionRun $run): array => [
                'action' => $run->action_name,
                'succeeded' => $run->succeeded(),
                'trigger' => $run->trigger,
                'ticket' => $run->ticket_id,
                'at' => $run->started_at->toIso8601String(),
                'failed_step' => collect($run->steps)->firstWhere('status', 'failed'),
            ])->all();

        return SkillResult::ok(count($runs).' runs.', ['runs' => $runs]);
    }
}
