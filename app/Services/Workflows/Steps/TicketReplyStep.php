<?php

namespace App\Services\Workflows\Steps;

use App\Services\Workflows\RunContext;

/**
 * `ticket/reply`: adds a reply. Defaults to the ticket that triggered the run.
 */
class TicketReplyStep extends BaseStep
{
    protected function keys(): array
    {
        return ['ticket' => false, 'body' => true, 'as' => false];
    }

    protected function checks(array $with, string $path): array
    {
        return $this->plainText($with, 'body', $path, 10000);
    }

    public function run(array $with, RunContext $context): array
    {
        $body = trim((string) $with['body']);
        if ($body === '') {
            throw new StepFailed('The reply is empty after filling in placeholders.');
        }

        $ticket = $context->targetTicket($with['ticket'] ?? null);
        $person = $context->person($with['as'] ?? null);
        $context->ensureAllowed($person, ['tickets.view', 'tickets.reply'], $ticket);
        $ticket->addComment($body, $person);

        return ['ticket' => $ticket->id];
    }
}
