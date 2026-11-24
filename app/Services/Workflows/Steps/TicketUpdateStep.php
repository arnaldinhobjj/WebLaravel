<?php

namespace App\Services\Workflows\Steps;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Workflows\RunContext;

/**
 * `ticket/update`: changes status, priority, category or assignee
 * (`assignee: none` unassigns). Defaults to the ticket that triggered the run.
 */
class TicketUpdateStep extends BaseStep
{
    private const FIELDS = ['status', 'priority', 'category', 'assignee'];

    protected function keys(): array
    {
        return ['ticket' => false, 'status' => false, 'priority' => false, 'category' => false, 'assignee' => false, 'as' => false];
    }

    protected function checks(array $with, string $path): array
    {
        $errors = array_intersect(self::FIELDS, array_keys($with)) === []
            ? ["{$path}: set at least one of status, priority, category or assignee."]
            : [];

        return [
            ...$errors,
            ...$this->oneOf($with, 'status', array_column(TicketStatus::cases(), 'value'), $path),
            ...$this->oneOf($with, 'priority', array_column(TicketPriority::cases(), 'value'), $path),
            ...$this->oneOf($with, 'category', array_keys(Ticket::CATEGORIES), $path),
        ];
    }

    public function run(array $with, RunContext $context): array
    {
        $ticket = $context->targetTicket($with['ticket'] ?? null);
        $changes = [];

        if (isset($with['status'])) {
            $this->requireOneOf($with['status'], 'status', array_column(TicketStatus::cases(), 'value'));
            $changes['status'] = $with['status'];
        }
        if (isset($with['priority'])) {
            $this->requireOneOf($with['priority'], 'priority', array_column(TicketPriority::cases(), 'value'));
            $changes['priority'] = $with['priority'];
        }
        if (isset($with['category'])) {
            $this->requireOneOf($with['category'], 'category', array_keys(Ticket::CATEGORIES));
            $changes['category'] = $with['category'];
        }
        if (isset($with['assignee'])) {
            $changes['assignee_id'] = in_array($with['assignee'], ['', 'none'], true)
                ? null
                : (User::where('username', $with['assignee'])->value('id')
                    ?? throw new StepFailed("No person with username \"{$with['assignee']}\" to assign."));
        }

        $changed = $ticket->applyChanges($changes, $context->person($with['as'] ?? null));

        return ['ticket' => $ticket->id, 'changed' => $changed];
    }
}
