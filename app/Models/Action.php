<?php

namespace App\Models;

use App\Enums\ActionType;
use App\Enums\TicketPriority;
use Database\Factories\ActionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A shortcut a person created on their actions page. Running it either opens a
 * link or opens the new ticket form filled in from the stored template.
 */
class Action extends Model
{
    /** @use HasFactory<ActionFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'type',
        'url',
        'ticket_subject',
        'ticket_category',
        'ticket_priority',
        'ticket_description',
        'show_on_home',
    ];

    protected $attributes = [
        'show_on_home' => true,
    ];

    /**
     * Always the people connection (MySQL by default), even when loaded through
     * a ticket: Eloquent otherwise reuses the parent model's connection.
     */
    public function getConnectionName(): ?string
    {
        return config('fortvale.connections.people');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ActionType::class,
            'ticket_priority' => TicketPriority::class,
            'show_on_home' => 'boolean',
            'last_run_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Where running this action takes the person.
     */
    public function destination(): string
    {
        if ($this->type === ActionType::Link) {
            return $this->url;
        }

        return route('tickets.create', array_filter([
            'subject' => $this->ticket_subject,
            'category' => $this->ticket_category,
            'priority' => $this->ticket_priority?->value,
            'description' => $this->ticket_description,
        ]));
    }

    public function opensExternalSite(): bool
    {
        return $this->type === ActionType::Link && ! str_starts_with($this->url, '/');
    }
}
