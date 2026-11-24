<?php

namespace App\Mcp\Tools;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('search_tickets')]
#[Description('Finds Fort Vale tickets the person may see, newest first. Optional: a search text (subject or number), a status (open, in_progress, waiting, resolved, closed, or "active" for the first three), mine ("assigned" or "opened").')]
class SearchTickets extends FortValeTool
{
    protected function permissions(): array
    {
        return ['tickets.view'];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->description('Words from the subject, or a ticket number.'),
            'status' => $schema->string()->enum(['active', 'open', 'in_progress', 'waiting', 'resolved', 'closed']),
            'mine' => $schema->string()->enum(['assigned', 'opened']),
            'limit' => $schema->integer()->min(1)->max(50)->default(20),
        ];
    }

    protected function perform(User $me, Request $request): Response
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:200'],
            'status' => ['nullable', 'in:active,open,in_progress,waiting,resolved,closed'],
            'mine' => ['nullable', 'in:assigned,opened'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = Ticket::query()->visibleTo($me)->with(['requester', 'assignee'])->latest('updated_at');
        if ($search = trim((string) ($data['search'] ?? ''))) {
            $number = ltrim($search, '#');
            $query->where(fn ($q) => $q->whereRaw('lower(subject) like ?', ['%'.mb_strtolower($search).'%'])
                ->when(ctype_digit($number), fn ($q) => $q->orWhere('id', (int) $number)));
        }
        match ($data['status'] ?? null) {
            null => null,
            'active' => $query->active(),
            default => $query->where('status', TicketStatus::from($data['status'])->value),
        };
        match ($data['mine'] ?? null) {
            'assigned' => $query->where('assignee_id', $me->id),
            'opened' => $query->where('requester_id', $me->id),
            default => null,
        };

        $tickets = $query->limit($data['limit'] ?? 20)->get()->map(fn (Ticket $ticket): array => [
            'id' => $ticket->id,
            'subject' => $ticket->subject,
            'status' => $ticket->status->value,
            'priority' => $ticket->priority->value,
            'category' => $ticket->category,
            'requester' => $ticket->requester?->name,
            'assignee' => $ticket->assignee?->name,
            'updated' => $ticket->updated_at->toIso8601String(),
        ])->all();

        return Response::json(['tickets' => $tickets, 'count' => count($tickets)]);
    }
}
