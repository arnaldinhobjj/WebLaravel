<?php

namespace App\Ai\Skills\Abilities;

use App\Ai\Skills\Skill;
use App\Ai\Skills\SkillResult;
use App\Models\User;

class TicketsActivity extends Skill
{
    public function name(): string
    {
        return 'tickets.activity';
    }

    public function title(): string
    {
        return 'Ticket history';
    }

    public function description(): string
    {
        return 'Everything that happened on a ticket, oldest first: opened, status, priority and assignee changes, and replies.';
    }

    public function properties(): array
    {
        return ['ticket' => ['type' => 'integer', 'description' => 'The ticket number.']];
    }

    public function required(): array
    {
        return ['ticket'];
    }

    public function permissions(): array
    {
        return ['tickets.view'];
    }

    public function run(User $me, array $input): SkillResult
    {
        $ticket = TicketsGet::visible($me, (int) $input['ticket'])->load('activities.user');

        $events = $ticket->activities->map(fn ($activity): array => [
            'at' => $activity->created_at->toIso8601String(),
            'by' => $activity->user?->name,
            'what' => $activity->type === 'comment' ? 'replied: '.$activity->body : $activity->summary(),
        ])->all();

        return SkillResult::ok(count($events)." events on ticket #{$ticket->id}.", ['ticket' => $ticket->id, 'events' => $events]);
    }
}
