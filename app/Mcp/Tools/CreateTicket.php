<?php

namespace App\Mcp\Tools;

use App\Enums\TicketPriority;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create_ticket')]
#[Description('Opens a new Fort Vale ticket as the person. Categories: it, quality, nuclear, dispatch, cctv, health, facilities, general. Priorities: low, normal, high, urgent.')]
class CreateTicket extends FortValeTool
{
    protected function permissions(): array
    {
        return ['tickets.create'];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'subject' => $schema->string()->required(),
            'description' => $schema->string()->required(),
            'category' => $schema->string()->enum(array_keys(Ticket::CATEGORIES))->default('general'),
            'priority' => $schema->string()->enum(array_column(TicketPriority::cases(), 'value'))->default('normal'),
        ];
    }

    protected function perform(User $me, Request $request): Response
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:160'],
            'description' => ['required', 'string', 'max:10000'],
            'category' => ['nullable', Rule::in(array_keys(Ticket::CATEGORIES))],
            'priority' => ['nullable', Rule::enum(TicketPriority::class)],
        ]);

        $ticket = Ticket::open([
            'subject' => $data['subject'],
            'description' => $data['description'],
            'category' => $data['category'] ?? 'general',
            'priority' => $data['priority'] ?? 'normal',
        ], $me);

        return Response::json(['created' => true, 'ticket' => $ticket->id, 'url' => route('tickets.show', $ticket)]);
    }
}
