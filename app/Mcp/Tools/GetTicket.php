<?php

namespace App\Mcp\Tools;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('get_ticket')]
#[Description('Reads one Fort Vale ticket by number: its details, description and replies.')]
class GetTicket extends FortValeTool
{
    protected function permissions(): array
    {
        return ['tickets.view'];
    }

    public function schema(JsonSchema $schema): array
    {
        return ['ticket' => $schema->integer()->description('The ticket number.')->required()];
    }

    protected function perform(User $me, Request $request): Response
    {
        $id = (int) $request->validate(['ticket' => ['required', 'integer']])['ticket'];
        $ticket = Ticket::with(['requester', 'assignee', 'comments.user'])->find($id);

        if ($ticket === null || ! $ticket->isVisibleTo($me)) {
            return Response::error("Ticket #{$id} wasn't found.");
        }

        return Response::json([
            'id' => $ticket->id,
            'subject' => $ticket->subject,
            'description' => $ticket->description,
            'status' => $ticket->status->value,
            'priority' => $ticket->priority->value,
            'category' => $ticket->category,
            'requester' => $ticket->requester?->name,
            'assignee' => $ticket->assignee?->name,
            'url' => route('tickets.show', $ticket),
            'replies' => $ticket->comments->map(fn ($reply): array => [
                'by' => $reply->user?->name,
                'at' => $reply->created_at->toIso8601String(),
                'text' => $reply->body,
            ])->all(),
        ]);
    }
}
