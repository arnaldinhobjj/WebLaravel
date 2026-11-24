<?php

namespace App\Ai\Skills\Abilities;

use App\Ai\Skills\Present;
use App\Ai\Skills\Skill;
use App\Ai\Skills\SkillFailed;
use App\Ai\Skills\SkillResult;
use App\Models\User;

class TicketsAssign extends Skill
{
    public function name(): string
    {
        return 'tickets.assign';
    }

    public function title(): string
    {
        return 'Assign a ticket';
    }

    public function description(): string
    {
        return 'Assign a ticket to someone by username (people.find gives usernames), to "me", or "none" to unassign.';
    }

    public function properties(): array
    {
        return [
            'ticket' => ['type' => 'integer'],
            'to' => ['type' => 'string', 'maxLength' => 60, 'description' => 'A username, "me" or "none".'],
        ];
    }

    public function required(): array
    {
        return ['ticket', 'to'];
    }

    public function permissions(): array
    {
        return ['tickets.view', 'tickets.assign'];
    }

    public function changesThings(): bool
    {
        return true;
    }

    public function run(User $me, array $input): SkillResult
    {
        $ticket = TicketsGet::visible($me, (int) $input['ticket']);
        $to = strtolower(trim($input['to']));
        $assignee = match ($to) {
            'none' => null,
            'me' => $me,
            default => User::where('username', $to)->first() ?? throw new SkillFailed("No person with username “{$to}”."),
        };

        $ticket->applyChanges(['assignee_id' => $assignee?->id], $me);

        return SkillResult::ok($assignee ? "Ticket #{$ticket->id} assigned to {$assignee->name}." : "Ticket #{$ticket->id} unassigned.", Present::ticket($ticket->fresh(['requester', 'assignee'])));
    }
}
