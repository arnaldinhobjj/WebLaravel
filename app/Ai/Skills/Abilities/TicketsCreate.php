<?php

namespace App\Ai\Skills\Abilities;

use App\Ai\Skills\Present;
use App\Ai\Skills\Skill;
use App\Ai\Skills\SkillResult;
use App\Models\Ticket;
use App\Models\User;

class TicketsCreate extends Skill
{
    public function name(): string
    {
        return 'tickets.create';
    }

    public function title(): string
    {
        return 'Open a ticket';
    }

    public function description(): string
    {
        return 'Open a new ticket as the person. Confirm the subject and details with them first.';
    }

    public function properties(): array
    {
        return [
            'subject' => ['type' => 'string', 'maxLength' => 160],
            'description' => ['type' => 'string', 'maxLength' => 10000],
            'category' => ['type' => 'string', 'enum' => array_keys(Ticket::CATEGORIES), 'description' => 'Default general.'],
            'priority' => ['type' => 'string', 'enum' => ['low', 'normal', 'high', 'urgent'], 'description' => 'Default normal.'],
        ];
    }

    public function required(): array
    {
        return ['subject', 'description'];
    }

    public function permissions(): array
    {
        return ['tickets.create'];
    }

    public function changesThings(): bool
    {
        return true;
    }

    public function run(User $me, array $input): SkillResult
    {
        $ticket = Ticket::open([
            'subject' => $input['subject'],
            'description' => $input['description'],
            'category' => $input['category'] ?? 'general',
            'priority' => $input['priority'] ?? 'normal',
        ], $me);

        return SkillResult::ok("Ticket #{$ticket->id} opened.", Present::ticket($ticket->load(['requester', 'assignee'])));
    }
}
