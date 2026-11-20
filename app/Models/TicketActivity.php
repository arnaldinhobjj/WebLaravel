<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketActivity extends Model
{
    protected $fillable = ['type', 'user_id', 'body', 'from', 'to'];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** One-line description for non-comment events in the timeline. */
    public function summary(): string
    {
        return match ($this->type) {
            'created' => 'opened this ticket',
            'status' => "changed the status from {$this->from} to {$this->to}",
            'priority' => "changed the priority from {$this->from} to {$this->to}",
            'assignee' => match (true) {
                $this->to === null => 'unassigned '.($this->from ?? 'the ticket'),
                $this->from === null => "assigned this to {$this->to}",
                default => "reassigned this from {$this->from} to {$this->to}",
            },
            'category' => "moved this to {$this->to}",
            default => 'updated this ticket',
        };
    }
}
