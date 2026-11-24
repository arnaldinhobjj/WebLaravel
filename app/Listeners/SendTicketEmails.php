<?php

namespace App\Listeners;

use App\Events\TicketChanged;
use App\Models\AppSetting;
use App\Models\EmailTemplate;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Email\Mailer;

use function Illuminate\Support\defer;

/**
 * Emails the people involved in a ticket when something happens to it, using
 * the built-in templates. Sent after the response so pages stay fast.
 *
 * Never emailed: whoever caused the change, people who turned that email off
 * in Settings, and people who can't see the ticket.
 */
class SendTicketEmails
{
    public function __construct(private Mailer $mailer) {}

    public function handle(TicketChanged $event): void
    {
        $ticketId = $event->ticket->id;
        $type = $event->type;
        $actor = $event->actor;

        defer(function () use ($ticketId, $type, $actor): void {
            $ticket = Ticket::find($ticketId);
            if ($ticket !== null) {
                $this->send($ticket, $type, $actor);
            }
        });
    }

    public function send(Ticket $ticket, string $type, User $actor): void
    {
        $template = EmailTemplate::where('key', EmailTemplate::TICKET_EVENTS[$type] ?? null)->first();
        if ($template === null) {
            return;
        }

        $people = match ($type) {
            'created', 'assigned' => [$ticket->assignee],
            default => [$ticket->requester, $ticket->assignee],
        };

        $recipients = [];
        foreach (array_filter($people) as $person) {
            if ($person->is($actor) || ! $person->wantsEmail($type) || ! $person->can('tickets.view') || ! $ticket->isVisibleTo($person)) {
                continue;
            }
            $recipients[strtolower($person->email)] = $person->name;
        }

        if ($type === 'created') {
            foreach (AppSetting::get('mail.category_recipients', [])[$ticket->category] ?? [] as $address) {
                $recipients[strtolower($address)] ??= $address;
            }
        }

        $values = [
            'ticket' => Mailer::ticketValues($ticket),
            'actor' => ['name' => $actor->name, 'username' => $actor->username],
            'reply' => ['body' => $type === 'replied' ? (string) $ticket->comments()->latest('id')->value('body') : ''],
        ];

        $this->mailer->send($recipients, $template->subject, $template->body, $values, 'ticket', $actor, route('tickets.show', $ticket));
    }
}
