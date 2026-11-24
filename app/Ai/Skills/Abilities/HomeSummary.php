<?php

namespace App\Ai\Skills\Abilities;

use App\Ai\Skills\Skill;
use App\Ai\Skills\SkillResult;
use App\Enums\TicketStatus;
use App\Models\ActionRun;
use App\Models\Ticket;
use App\Models\User;

class HomeSummary extends Skill
{
    public function name(): string
    {
        return 'home.summary';
    }

    public function title(): string
    {
        return 'Today at a glance';
    }

    public function description(): string
    {
        return 'Numbers for the person\'s day: unfinished tickets they can see, urgent ones, waiting ones, assigned to them, opened by them, and action runs that failed in the last day.';
    }

    public function permissions(): array
    {
        return ['tickets.view'];
    }

    public function run(User $me, array $input): SkillResult
    {
        $visible = fn () => Ticket::query()->visibleTo($me)->active();

        $numbers = [
            'unfinished_tickets' => $visible()->count(),
            'urgent' => $visible()->where('priority', 'urgent')->count(),
            'waiting' => $visible()->where('status', TicketStatus::Waiting->value)->count(),
            'assigned_to_me' => $visible()->where('assignee_id', $me->id)->count(),
            'opened_by_me' => $visible()->where('requester_id', $me->id)->count(),
            'failed_runs_last_day' => ActionRun::visibleTo($me)->where('status', ActionRun::FAILED)->where('started_at', '>=', now()->subDay())->count(),
        ];

        return SkillResult::ok("{$numbers['assigned_to_me']} assigned to you, {$numbers['urgent']} urgent, {$numbers['unfinished_tickets']} unfinished in total.", $numbers);
    }
}
