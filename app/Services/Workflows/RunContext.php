<?php

namespace App\Services\Workflows;

use App\Models\Ticket;
use App\Models\User;
use App\Services\Workflows\Steps\StepFailed;

/**
 * Everything a running action can see and produce: who started it, the
 * ticket that triggered it, inputs, earlier steps' outputs, and the message
 * or page to show afterwards.
 */
class RunContext
{
    /** @var array<string, array<string, mixed>> Outputs keyed by step id. */
    public array $stepOutputs = [];

    public ?string $message = null;

    public ?string $redirect = null;

    /**
     * @param  array<string, string>  $inputs
     * @param  array<string, string>  $secrets
     */
    public function __construct(
        public readonly Workflow $workflow,
        public readonly string $trigger,
        public readonly ?User $actor,
        public ?Ticket $ticket = null,
        public readonly array $inputs = [],
        public readonly array $secrets = [],
    ) {}

    /**
     * Values available to `${{ … }}` placeholders.
     *
     * @return array<string, mixed>
     */
    public function values(): array
    {
        return [
            'inputs' => $this->inputs,
            'ticket' => $this->ticket ? [
                'id' => $this->ticket->id,
                'subject' => $this->ticket->subject,
                'status' => $this->ticket->status->value,
                'priority' => $this->ticket->priority->value,
                'category' => $this->ticket->category,
            ] : [],
            'actor' => $this->actor ? ['name' => $this->actor->name, 'username' => $this->actor->username] : [],
            'steps' => $this->stepOutputs,
            'secrets' => $this->secrets,
        ];
    }

    /**
     * The person a step acts as: `as: username` if given, otherwise whoever started the run.
     */
    public function person(?string $username): User
    {
        if ($username !== null && $username !== '') {
            return User::where('username', $username)->first()
                ?? throw new StepFailed("No person with username \"{$username}\".");
        }

        return $this->actor
            ?? throw new StepFailed('This run has no person to act as. Add `as: <username>` to the step.');
    }

    /**
     * The ticket a step works on: `ticket: <id>` if given, otherwise the ticket that triggered the run.
     */
    public function targetTicket(mixed $id): Ticket
    {
        if ($id !== null && $id !== '') {
            if (! ctype_digit((string) $id)) {
                throw new StepFailed("\"{$id}\" is not a ticket number.");
            }

            return Ticket::find((int) $id) ?? throw new StepFailed("Ticket #{$id} doesn't exist.");
        }

        return $this->ticket
            ?? throw new StepFailed('No ticket to work on. Add `ticket: <number>` to the step.');
    }
}
