<?php

namespace App\Listeners;

use App\Events\TicketChanged;
use App\Models\AppSetting;
use App\Models\EmailTemplate;
use App\Models\StoredFile;
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

        $reply = $type === 'replied' ? $ticket->comments()->latest('id')->first() : null;
        $values = [
            'ticket' => Mailer::ticketValues($ticket),
            'actor' => ['name' => $actor->name, 'username' => $actor->username],
            'reply' => ['body' => (string) $reply?->body],
        ];

        // Files aren't attached to automatic emails (to keep them small); say they're there.
        $attachments = match (true) {
            $type === 'created' => StoredFile::where('attachable_type', 'ticket')->where('attachable_id', (string) $ticket->id)->count(),
            $reply !== null => StoredFile::where('attachable_type', 'ticket_reply')->where('attachable_id', (string) $reply->id)->count(),
            default => 0,
        };
        $body = $template->body.($attachments > 0 ? "\n\n{$attachments} attached ".str('file')->plural($attachments).'. Open the ticket to see '.($attachments === 1 ? 'it' : 'them').'.' : '');

        $this->mailer->send($recipients, $template->subject, $body, $values, 'ticket', $actor, route('tickets.show', $ticket));
    }
}
