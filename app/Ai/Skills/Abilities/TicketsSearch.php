<?php

namespace App\Ai\Skills\Abilities;

use App\Ai\Skills\Present;
use App\Ai\Skills\Skill;
use App\Ai\Skills\SkillResult;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;

class TicketsSearch extends Skill
{
    public function name(): string
    {
        return 'tickets.search';
    }

    public function title(): string
    {
        return 'Search tickets';
    }

    public function description(): string
    {
        return 'Find tickets the person may see, newest first. Filter by words in the subject or a ticket number, status ("active" = open, in progress or waiting), priority, category, or "mine" (assigned to them or opened by them).';
    }

    public function properties(): array
    {
        return [
            'search' => ['type' => 'string', 'description' => 'Words from the subject, or a ticket number.', 'maxLength' => 200],
            'status' => ['type' => 'string', 'enum' => ['active', 'open', 'in_progress', 'waiting', 'resolved', 'closed']],
            'priority' => ['type' => 'string', 'enum' => ['low', 'normal', 'high', 'urgent']],
            'category' => ['type' => 'string', 'enum' => array_keys(Ticket::CATEGORIES)],
            'mine' => ['type' => 'string', 'enum' => ['assigned', 'opened']],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'description' => 'How many (default 20).'],
        ];
    }

    public function permissions(): array
    {
        return ['tickets.view'];
    }

    public function run(User $me, array $input): SkillResult
    {
        $query = Ticket::query()->visibleTo($me)->with(['requester', 'assignee'])->latest('updated_at');

        if ($search = trim((string) ($input['search'] ?? ''))) {
            $number = ltrim($search, '#');
            $query->where(fn ($q) => $q->whereRaw('lower(subject) like ?', ['%'.mb_strtolower($search).'%'])
                ->when(ctype_digit($number), fn ($q) => $q->orWhere('id', (int) $number)));
        }
        match ($input['status'] ?? null) {
            null => null,
            'active' => $query->active(),
            default => $query->where('status', TicketStatus::from($input['status'])->value),
        };
        if (! empty($input['priority'])) {
            $query->where('priority', $input['priority']);
        }
        if (! empty($input['category'])) {
            $query->where('category', $input['category']);
        }
        match ($input['mine'] ?? null) {
            'assigned' => $query->where('assignee_id', $me->id),
            'opened' => $query->where('requester_id', $me->id),
            default => null,
        };

        $tickets = $query->limit((int) ($input['limit'] ?? 20))->get()->map(fn (Ticket $ticket) => Present::ticket($ticket))->all();

        return SkillResult::ok(count($tickets).' '.str('ticket')->plural(count($tickets)).' found.', ['tickets' => $tickets]);
    }
}
