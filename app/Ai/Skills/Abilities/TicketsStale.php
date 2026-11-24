<?php

namespace App\Ai\Skills\Abilities;

use App\Ai\Skills\Present;
use App\Ai\Skills\Skill;
use App\Ai\Skills\SkillResult;
use App\Models\Ticket;
use App\Models\User;

class TicketsStale extends Skill
{
    public function name(): string
    {
        return 'tickets.stale';
    }

    public function title(): string
    {
        return 'Tickets nobody touched lately';
    }

    public function description(): string
    {
        return 'Unfinished tickets the person may see that haven\'t changed for a number of days (default 5), oldest first.';
    }

    public function properties(): array
    {
        return ['days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 365, 'description' => 'Not changed for at least this many days.']];
    }

    public function permissions(): array
    {
        return ['tickets.view'];
    }

    public function run(User $me, array $input): SkillResult
    {
        $days = (int) ($input['days'] ?? 5);
        $tickets = Ticket::query()->visibleTo($me)->active()->where('updated_at', '<=', now()->subDays($days))
            ->with(['requester', 'assignee'])->oldest('updated_at')->limit(50)->get()
            ->map(fn (Ticket $ticket) => Present::ticket($ticket))->all();

        return SkillResult::ok(count($tickets)." unfinished tickets unchanged for {$days}+ days.", ['tickets' => $tickets]);
    }
}
