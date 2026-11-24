<?php

namespace App\Listeners;

use App\Events\TicketChanged;
use App\Models\Ticket;
use App\Services\Workflows\WorkflowRepository;
use App\Services\Workflows\WorkflowRunner;

use function Illuminate\Support\defer;

/**
 * Runs the actions listening to a ticket event, after the response has been
 * sent so the page stays fast. Changes made by an action don't trigger more
 * actions, which prevents loops.
 */
class RunTicketActions
{
    public function __construct(
        private WorkflowRepository $workflows,
        private WorkflowRunner $runner,
    ) {}

    public function handle(TicketChanged $event): void
    {
        if (WorkflowRunner::isRunning()) {
            return;
        }

        $workflows = $this->workflows->forTicketEvent($event->ticket, $event->type);
        if ($workflows === []) {
            return;
        }

        $ticketId = $event->ticket->id;
        $actor = $event->actor;
        $type = $event->type;

        defer(function () use ($workflows, $ticketId, $actor, $type): void {
            foreach ($workflows as $workflow) {
                $ticket = Ticket::find($ticketId);
                if ($ticket === null) {
                    return;
                }

                $this->runner->run($workflow, 'ticket', $actor, [], $ticket, $type);
            }
        });
    }
}
