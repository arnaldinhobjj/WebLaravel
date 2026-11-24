<?php

namespace App\Services\Workflows\Steps;

use App\Enums\TicketPriority;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Workflows\RunContext;

/**
 * `ticket/create`: opens a new ticket. Outputs `ticket` (its number).
 */
class TicketCreateStep extends BaseStep
{
    protected function keys(): array
    {
        return ['subject' => true, 'description' => false, 'category' => false, 'priority' => false, 'assignee' => false, 'as' => false];
    }

    protected function checks(array $with, string $path): array
    {
        return [
            ...$this->plainText($with, 'subject', $path, 160),
            ...$this->plainText($with, 'description', $path, 10000),
            ...$this->oneOf($with, 'category', array_keys(Ticket::CATEGORIES), $path),
            ...$this->oneOf($with, 'priority', array_column(TicketPriority::cases(), 'value'), $path),
        ];
    }

    public function run(array $with, RunContext $context): array
    {
        $category = $with['category'] ?? 'general';
        $priority = $with['priority'] ?? 'normal';
        $this->requireOneOf($category, 'category', array_keys(Ticket::CATEGORIES));
        $this->requireOneOf($priority, 'priority', array_column(TicketPriority::cases(), 'value'));

        $subject = trim((string) $with['subject']);
        if ($subject === '') {
            throw new StepFailed('The subject is empty after filling in placeholders.');
        }

        $requester = $context->person($with['as'] ?? null);
        $context->ensureAllowed($requester, empty($with['assignee']) ? ['tickets.create'] : ['tickets.create', 'tickets.assign']);
        $ticket = Ticket::open([
            'subject' => mb_substr($subject, 0, 160),
            'description' => trim((string) ($with['description'] ?? '')) ?: $subject,
            'category' => $category,
            'priority' => $priority,
        ], $requester);

        if (! empty($with['assignee'])) {
            $assignee = User::where('username', $with['assignee'])->first()
                ?? throw new StepFailed("No person with username \"{$with['assignee']}\" to assign.");
            $ticket->applyChanges(['assignee_id' => $assignee->id], $requester);
        }

        return ['ticket' => $ticket->id];
    }
}
