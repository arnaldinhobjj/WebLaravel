<?php

namespace App\Ai\Skills\Abilities;

use App\Ai\Skills\Present;
use App\Ai\Skills\Skill;
use App\Ai\Skills\SkillResult;
use App\Models\Ticket;
use App\Models\User;

class TicketsMyQueue extends Skill
{
    public function name(): string
    {
        return 'tickets.my_queue';
    }

    public function title(): string
    {
        return 'My ticket queue';
    }

    public function description(): string
    {
        return 'The person\'s unfinished tickets: assigned to them and opened by them, grouped by status, most urgent first.';
    }

    public function permissions(): array
    {
        return ['tickets.view'];
    }

    public function run(User $me, array $input): SkillResult
    {
        $rank = ['urgent' => 0, 'high' => 1, 'normal' => 2, 'low' => 3];
        $group = fn ($query) => $query->active()->with(['requester', 'assignee'])->get()
            ->sortBy(fn (Ticket $ticket) => [$rank[$ticket->priority->value] ?? 9, -$ticket->updated_at->timestamp])
            ->groupBy(fn (Ticket $ticket) => $ticket->status->value)
            ->map(fn ($tickets) => $tickets->map(fn (Ticket $ticket) => Present::ticket($ticket))->values())
            ->all();

        $assigned = $group(Ticket::where('assignee_id', $me->id));
        $opened = $group(Ticket::where('requester_id', $me->id));
        $count = fn (array $groups) => array_sum(array_map('count', $groups));

        return SkillResult::ok("{$count($assigned)} assigned to you, {$count($opened)} opened by you, unfinished.", [
            'assigned_to_me' => $assigned,
            'opened_by_me' => $opened,
        ]);
    }
}
