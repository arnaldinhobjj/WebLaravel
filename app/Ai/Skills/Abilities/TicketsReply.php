<?php

namespace App\Ai\Skills\Abilities;

use App\Ai\Skills\Skill;
use App\Ai\Skills\SkillResult;
use App\Models\User;

class TicketsReply extends Skill
{
    public function name(): string
    {
        return 'tickets.reply';
    }

    public function title(): string
    {
        return 'Reply to a ticket';
    }

    public function description(): string
    {
        return 'Post a reply on a ticket as the person. Show them the text and confirm before posting.';
    }

    public function properties(): array
    {
        return [
            'ticket' => ['type' => 'integer'],
            'text' => ['type' => 'string', 'maxLength' => 10000, 'description' => 'The reply.'],
        ];
    }

    public function required(): array
    {
        return ['ticket', 'text'];
    }

    public function permissions(): array
    {
        return ['tickets.view', 'tickets.reply'];
    }

    public function changesThings(): bool
    {
        return true;
    }

    public function run(User $me, array $input): SkillResult
    {
        $ticket = TicketsGet::visible($me, (int) $input['ticket']);
        $ticket->addComment($input['text'], $me);

        return SkillResult::ok("Replied on ticket #{$ticket->id}.", ['ticket' => $ticket->id, 'url' => route('tickets.show', $ticket)]);
    }
}
