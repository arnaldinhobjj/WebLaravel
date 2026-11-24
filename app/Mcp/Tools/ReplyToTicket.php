<?php

namespace App\Mcp\Tools;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('reply_to_ticket')]
#[Description('Posts a reply on a Fort Vale ticket as the person.')]
class ReplyToTicket extends FortValeTool
{
    protected function permissions(): array
    {
        return ['tickets.view', 'tickets.reply'];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'ticket' => $schema->integer()->required(),
            'text' => $schema->string()->description('The reply.')->required(),
        ];
    }

    protected function perform(User $me, Request $request): Response
    {
        $data = $request->validate(['ticket' => ['required', 'integer'], 'text' => ['required', 'string', 'max:10000']]);
        $ticket = Ticket::find((int) $data['ticket']);

        if ($ticket === null || ! $ticket->isVisibleTo($me)) {
            return Response::error("Ticket #{$data['ticket']} wasn't found.");
        }

        $ticket->addComment($data['text'], $me);

        return Response::json(['replied' => true, 'ticket' => $ticket->id, 'url' => route('tickets.show', $ticket)]);
    }
}
