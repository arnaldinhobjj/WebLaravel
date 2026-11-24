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
                'url' => route('tickets.show', $this->ticket),
                'requester' => $this->ticket->requester?->username,
                'assignee' => $this->ticket->assignee?->username,
            ] : [],
            'actor' => $this->actor ? ['name' => $this->actor->name, 'username' => $this->actor->username] : [],
            'steps' => $this->stepOutputs,
            'secrets' => $this->secrets,
        ];
    }

    /**
     * Checks that a step is allowed:
     * - personal actions: their owner must see the ticket and have the permissions;
     * - shared actions someone runs (Run button, or an AI as that person): the
     *   person running it must, even when the step acts `as:` someone else;
     * - shared actions started by a ticket event or a schedule: trusted, since
     *   only people allowed to edit shared actions write them.
     *
     * @param  list<string>  $permissions
     */
    public function ensureAllowed(User $person, array $permissions, ?Ticket $ticket = null): void
    {
        $person = match (true) {
            ! $this->workflow->isShared() => $person,
            $this->trigger === 'run' && $this->actor !== null => $this->actor,
            default => null,
        };
        if ($person === null) {
            return;
        }

        if ($ticket !== null && ! $ticket->isVisibleTo($person)) {
            throw new StepFailed("Ticket #{$ticket->id} isn't one {$person->name} can see.");
        }
        foreach ($permissions as $permission) {
            if (! $person->hasPermission($permission)) {
                throw new StepFailed("{$person->name} isn't allowed to do that ({$permission}).");
            }
        }
    }

    /**
     * Whether a file step may use this person's My files: always for a
     * personal action's owner; for shared actions only the person running it.
     */
    public function mayUseMyFilesOf(User $person): bool
    {
        return ! $this->workflow->isShared() || ($this->actor !== null && $this->actor->is($person));
    }

    /**
     * Whose file access a file step uses. Personal actions always use their
     * owner's (so they can't reach someone else's files); shared actions use
     * `as:` or whoever started the run, or nobody for a scheduled run.
     */
    public function filesPerson(?string $username): ?User
    {
        if (! $this->workflow->isShared()) {
            return User::find($this->workflow->ownerId)
                ?? throw new StepFailed('The owner of this action no longer exists.');
        }

        return ($username !== null && $username !== '') || $this->actor !== null ? $this->person($username) : null;
    }

    /**
     * The person a step acts as: `as: username` if given, otherwise whoever started the run.
     */
    public function person(?string $username): User
    {
        // Personal actions always act as their owner, whoever triggered them.
        if (! $this->workflow->isShared()) {
            $owner = User::find($this->workflow->ownerId) ?? throw new StepFailed('The owner of this action no longer exists.');
            if ($username !== null && $username !== '' && $username !== $owner->username) {
                throw new StepFailed('Personal actions always act as their owner; remove `as`.');
            }

            return $owner;
        }

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
