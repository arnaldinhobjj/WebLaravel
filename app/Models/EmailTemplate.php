<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A reusable email. Built-in templates (with a `key`) are used for ticket
 * emails and can be edited but not deleted.
 */
#[Fillable(['name', 'subject', 'body'])]
class EmailTemplate extends Model
{
    /** Built-in template keys, one per ticket event. */
    public const TICKET_EVENTS = [
        'created' => 'ticket.created',
        'assigned' => 'ticket.assigned',
        'replied' => 'ticket.replied',
        'status_changed' => 'ticket.status_changed',
    ];

    public function getConnectionName(): ?string
    {
        return config('fortvale.connections.people');
    }

    public function isBuiltIn(): bool
    {
        return $this->key !== null;
    }
}
