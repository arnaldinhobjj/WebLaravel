<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketActivity extends Model
{
    protected $fillable = ['type', 'user_id', 'body', 'from_label', 'to_label'];

    /**
     * Stored next to its ticket, see config/fortvale.php.
     */
    public function getConnectionName(): ?string
    {
        return config('fortvale.connections.tickets');
    }

    /**
     * Oracle's driver returns numbers as strings; keep ids comparable everywhere.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ticket_id' => 'integer',
            'user_id' => 'integer',
        ];
    }

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
            'status' => "changed the status from {$this->from_label} to {$this->to_label}",
            'priority' => "changed the priority from {$this->from_label} to {$this->to_label}",
            'assignee' => match (true) {
                $this->to_label === null => 'unassigned '.($this->from_label ?? 'the ticket'),
                $this->from_label === null => "assigned this to {$this->to_label}",
                default => "reassigned this from {$this->from_label} to {$this->to_label}",
            },
            'category' => "moved this to {$this->to_label}",
            default => 'updated this ticket',
        };
    }
}
