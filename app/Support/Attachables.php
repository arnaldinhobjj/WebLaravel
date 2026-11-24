<?php

namespace App\Support;

use App\Models\EmailTemplate;
use App\Models\StoredFile;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\User;
use App\Services\Workflows\WorkflowRepository;
use Illuminate\Database\Eloquent\Collection;

/**
 * The pages files can be attached to, and who may see, add and remove their
 * attachments. Attachments follow the page: whoever can see a ticket sees its
 * attachments.
 *
 * To give another page attachments: add a type here (how to find the record,
 * who may view and attach, and where it lives), then put
 * `<x-attachments type="…" :id="…" />` on its page.
 */
class Attachables
{
    /** @var array<string, string> type => label */
    public const TYPES = [
        'ticket' => 'Ticket',
        'ticket_reply' => 'Ticket reply',
        'email_template' => 'Email template',
        'shared_action' => 'Shared action',
    ];

    /**
     * The record behind an attachment target, or null if it doesn't exist.
     */
    public static function find(string $type, string $id): mixed
    {
        return match ($type) {
            'ticket' => ctype_digit($id) ? Ticket::find((int) $id) : null,
            'ticket_reply' => ctype_digit($id) ? TicketActivity::where('type', 'comment')->find((int) $id) : null,
            'email_template' => ctype_digit($id) ? EmailTemplate::find((int) $id) : null,
            'shared_action' => app(WorkflowRepository::class)->sharedFilePath($id) !== null ? $id : null,
            default => null,
        };
    }

    public static function canView(User $person, string $type, string $id): bool
    {
        $record = self::find($type, $id);

        return $record !== null && match ($type) {
            'ticket' => $person->can('tickets.view') && $record->isVisibleTo($person),
            'ticket_reply' => $person->can('tickets.view') && $record->ticket !== null && $record->ticket->isVisibleTo($person),
            'email_template' => $person->can('email_templates.view'),
            'shared_action' => $person->can('shared_actions.view'),
        };
    }

    /**
     * Adding files from the attachments box. Reply attachments are only added
     * together with the reply.
     */
    public static function canAttach(User $person, string $type, string $id): bool
    {
        return self::canView($person, $type, $id) && match ($type) {
            'ticket' => $person->can('tickets.reply'),
            'email_template' => $person->can('email_templates.edit'),
            'shared_action' => $person->can('shared_actions.edit'),
            default => false,
        };
    }

    /**
     * Whoever added a file may remove it; so may people who can edit the page.
     */
    public static function canDelete(User $person, StoredFile $file): bool
    {
        $type = (string) $file->attachable_type;
        $id = (string) $file->attachable_id;

        if (! self::canView($person, $type, $id)) {
            return false;
        }

        return $file->created_by === $person->id || match ($type) {
            'ticket', 'ticket_reply' => $person->can('tickets.edit'),
            'email_template' => $person->can('email_templates.edit'),
            'shared_action' => $person->can('shared_actions.edit'),
            default => false,
        };
    }

    /**
     * The page an attachment belongs to.
     */
    public static function url(string $type, string $id): string
    {
        return match ($type) {
            'ticket' => route('tickets.show', (int) $id),
            'ticket_reply' => ($reply = self::find($type, $id)) ? route('tickets.show', $reply->ticket_id).'#reply-'.$id : route('tickets.index'),
            'email_template' => route('email-templates.edit', (int) $id),
            'shared_action' => route('actions.shared.show', $id),
            default => route('dashboard'),
        };
    }

    /**
     * Attachments of one page, oldest first.
     *
     * @return Collection<int, StoredFile>
     */
    public static function filesFor(string $type, string|int $id): Collection
    {
        return StoredFile::where('attachable_type', $type)->where('attachable_id', (string) $id)
            ->with('creator')->orderBy('id')->get();
    }
}
