<?php

namespace App\Ai\Skills\Abilities;

use App\Ai\Skills\Present;
use App\Ai\Skills\Skill;
use App\Ai\Skills\SkillFailed;
use App\Ai\Skills\SkillResult;
use App\Models\Ticket;
use App\Models\User;

class TicketsGet extends Skill
{
    public function name(): string
    {
        return 'tickets.get';
    }

    public function title(): string
    {
        return 'Read a ticket';
    }

    public function description(): string
    {
        return 'Read one ticket by number: its details, description and every reply.';
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
        $ticket = self::visible($me, (int) $input['ticket']);
        $ticket->load(['requester', 'assignee', 'comments.user']);

        return SkillResult::ok("Ticket #{$ticket->id}: {$ticket->subject}", Present::ticket($ticket) + [
            'description' => $ticket->description,
            'replies' => $ticket->comments->map(fn ($reply): array => [
                'by' => $reply->user?->name,
                'at' => $reply->created_at->toIso8601String(),
                'text' => $reply->body,
            ])->all(),
        ]);
    }

    /**
     * A ticket the person may see, or a "not found" failure (never revealing others).
     */
    public static function visible(User $me, int $id): Ticket
    {
        $ticket = Ticket::find($id);
        if ($ticket === null || ! $ticket->isVisibleTo($me)) {
            throw new SkillFailed("Ticket #{$id} wasn't found.");
        }

        return $ticket;
    }
}
