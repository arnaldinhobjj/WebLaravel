<?php

namespace App\Ai\Skills\Abilities;

use App\Ai\Skills\Present;
use App\Ai\Skills\Skill;
use App\Ai\Skills\SkillFailed;
use App\Ai\Skills\SkillResult;
use App\Models\Ticket;
use App\Models\User;

class TicketsUpdate extends Skill
{
    public function name(): string
    {
        return 'tickets.update';
    }

    public function title(): string
    {
        return 'Change a ticket';
    }

    public function description(): string
    {
        return 'Change a ticket\'s status, priority or category. Give only what should change.';
    }

    public function properties(): array
    {
        return [
            'ticket' => ['type' => 'integer'],
            'status' => ['type' => 'string', 'enum' => ['open', 'in_progress', 'waiting', 'resolved', 'closed']],
            'priority' => ['type' => 'string', 'enum' => ['low', 'normal', 'high', 'urgent']],
            'category' => ['type' => 'string', 'enum' => array_keys(Ticket::CATEGORIES)],
        ];
    }

    public function required(): array
    {
        return ['ticket'];
    }

    public function permissions(): array
    {
        return ['tickets.view', 'tickets.edit'];
    }

    public function changesThings(): bool
    {
        return true;
    }

    public function run(User $me, array $input): SkillResult
    {
        $ticket = TicketsGet::visible($me, (int) $input['ticket']);
        $changes = array_filter(['status' => $input['status'] ?? null, 'priority' => $input['priority'] ?? null, 'category' => $input['category'] ?? null]);
        if ($changes === []) {
            throw new SkillFailed('Say what to change: status, priority or category.');
        }

        $changed = $ticket->applyChanges($changes, $me);

        return SkillResult::ok($changed ? "Ticket #{$ticket->id} updated." : "Ticket #{$ticket->id} already had those values.", Present::ticket($ticket->fresh(['requester', 'assignee'])));
    }
}
