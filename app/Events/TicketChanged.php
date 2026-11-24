<?php

namespace App\Events;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Something happened to a ticket that actions can react to (`on: ticket`).
 */
class TicketChanged
{
    use Dispatchable;

    /**
     * @param  'created'|'status_changed'|'assigned'|'replied'  $type
     */
    public function __construct(
        public Ticket $ticket,
        public string $type,
        public User $actor,
    ) {}
}
