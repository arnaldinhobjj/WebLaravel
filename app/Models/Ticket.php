<?php

namespace App\Models;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Events\TicketChanged;
use Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ticket extends Model
{
    /** @use HasFactory<TicketFactory> */
    use HasFactory;

    public const CATEGORIES = [
        'it' => 'IT & printers',
        'quality' => 'Quality',
        'nuclear' => 'Nuclear',
        'dispatch' => 'Dispatch',
        'cctv' => 'CCTV',
        'health' => 'Health',
        'facilities' => 'Facilities',
        'general' => 'General',
    ];

    protected $fillable = ['subject', 'description', 'status', 'priority', 'category', 'requester_id', 'assignee_id'];

    protected $attributes = [
        'status' => 'open',
        'priority' => 'normal',
    ];

    /**
     * Tickets live on their own connection (Oracle by default), see config/fortvale.php.
     */
    public function getConnectionName(): ?string
    {
        return config('fortvale.connections.tickets');
    }

    protected function casts(): array
    {
        return [
            'status' => TicketStatus::class,
            'priority' => TicketPriority::class,
            'resolved_at' => 'datetime',
            // Oracle's driver returns numbers as strings; keep ids comparable everywhere.
            'requester_id' => 'integer',
            'assignee_id' => 'integer',
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(TicketActivity::class)->oldest()->oldest('id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TicketActivity::class)->where('type', 'comment');
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? 'General';
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', array_map(fn ($s) => $s->value, TicketStatus::active()));
    }

    /**
     * Tickets a person may see: the ones they opened or are assigned to, or
     * every ticket with the "see every ticket" permission.
     */
    public function scopeVisibleTo(Builder $query, User $person): void
    {
        if ($person->hasPermission('tickets.view_all')) {
            return;
        }

        $query->where(fn (Builder $q) => $q->where('requester_id', $person->id)->orWhere('assignee_id', $person->id));
    }

    /**
     * The filters of the ticket list (and of ticket table widgets, which link
     * to that list): q, priority, category, assignee, requester and
     * resolved_within. Unknown or invalid values are ignored.
     *
     * @param  Builder<Ticket>  $query
     * @param  array<string, mixed>  $filters
     */
    public function scopeFilteredBy(Builder $query, array $filters, User $me): void
    {
        $resolvedWithin = filter_var($filters['resolved_within'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 365]]);
        if ($resolvedWithin !== false) {
            $query->where('resolved_at', '>=', now()->subDays($resolvedWithin));
        }

        if ($search = trim((string) ($filters['q'] ?? ''))) {
            $term = ltrim($search, '#');
            $query->where(function (Builder $q) use ($search, $term) {
                // LOWER on both sides: Oracle compares case-sensitively, MySQL doesn't.
                $pattern = '%'.mb_strtolower($search).'%';
                $q->whereRaw('lower(subject) like ?', [$pattern])
                    ->orWhereRaw('lower(description) like ?', [$pattern]);
                if (ctype_digit($term)) {
                    $q->orWhere('id', (int) $term);
                }
            });
        }

        if ($priority = TicketPriority::tryFrom((string) ($filters['priority'] ?? ''))) {
            $query->where('priority', $priority);
        }

        if (isset(self::CATEGORIES[(string) ($filters['category'] ?? '')])) {
            $query->where('category', $filters['category']);
        }

        match ((string) ($filters['assignee'] ?? '')) {
            '' => null,
            'me' => $query->where('assignee_id', $me->id),
            'unassigned' => $query->whereNull('assignee_id'),
            default => $query->where('assignee_id', (int) $filters['assignee']),
        };

        if (($filters['requester'] ?? null) === 'me') {
            $query->where('requester_id', $me->id);
        }
    }

    /**
     * The status tabs of the ticket list: "active" (open, in progress,
     * waiting), "all", or one status.
     *
     * @param  Builder<Ticket>  $query
     */
    public function scopeInStatusTab(Builder $query, string $tab): void
    {
        if ($tab === 'active') {
            $query->active();
        } elseif ($status = TicketStatus::tryFrom($tab)) {
            $query->where('status', $status);
        }
    }

    /**
     * The sort orders of the ticket list: updated (default), priority, newest, oldest.
     *
     * @param  Builder<Ticket>  $query
     */
    public function scopeSortedBy(Builder $query, string $sort): void
    {
        match ($sort) {
            'priority' => $query
                ->orderByRaw("case priority when 'urgent' then 4 when 'high' then 3 when 'normal' then 2 else 1 end desc")
                ->latest('updated_at'),
            'newest' => $query->latest('created_at')->latest('id'),
            'oldest' => $query->oldest('created_at')->oldest('id'),
            default => $query->latest('updated_at')->latest('id'),
        };
    }

    public function isVisibleTo(User $person): bool
    {
        return $person->hasPermission('tickets.view_all')
            || $this->requester_id === $person->id
            || $this->assignee_id === $person->id;
    }

    /** Creates a ticket and records who opened it. */
    public static function open(array $attributes, User $requester): self
    {
        $ticket = (new self)->getConnection()->transaction(function () use ($attributes, $requester) {
            $ticket = self::create($attributes + ['requester_id' => $requester->id]);
            $ticket->activities()->create(['type' => 'created', 'user_id' => $requester->id]);

            return $ticket;
        });

        TicketChanged::dispatch($ticket, 'created', $requester);

        return $ticket;
    }

    /**
     * Applies changes to status, priority, assignee and category, logging each
     * change to the activity timeline. Returns the number of fields changed.
     */
    public function applyChanges(array $changes, User $actor): int
    {
        $events = [];

        $changed = $this->getConnection()->transaction(function () use ($changes, $actor, &$events) {
            $changed = 0;

            foreach (['status', 'priority', 'assignee_id', 'category'] as $field) {
                if (! array_key_exists($field, $changes)) {
                    continue;
                }

                $old = $this->{$field};
                $this->{$field} = $changes[$field];

                if (! $this->isDirty($field)) {
                    continue;
                }

                [$type, $from, $to] = match ($field) {
                    'status' => ['status', $old?->label(), $this->status->label()],
                    'priority' => ['priority', $old?->label(), $this->priority->label()],
                    'assignee_id' => ['assignee', User::find($old)?->name, User::find($this->assignee_id)?->name],
                    'category' => ['category', self::CATEGORIES[$old] ?? null, $this->categoryLabel()],
                };

                $this->activities()->create(['type' => $type, 'user_id' => $actor->id, 'from_label' => $from, 'to_label' => $to]);
                $changed++;

                if ($type === 'status') {
                    $events[] = 'status_changed';
                } elseif ($type === 'assignee' && $this->assignee_id !== null) {
                    $events[] = 'assigned';
                }
            }

            if ($this->isDirty('status')) {
                $this->resolved_at = $this->status->isActive() ? null : ($this->resolved_at ?? now());
            }

            $this->save();

            return $changed;
        });

        foreach ($events as $event) {
            TicketChanged::dispatch($this, $event, $actor);
        }

        return $changed;
    }

    public function addComment(string $body, User $author): TicketActivity
    {
        $comment = $this->activities()->create(['type' => 'comment', 'user_id' => $author->id, 'body' => $body]);
        $this->touch();

        TicketChanged::dispatch($this, 'replied', $author);

        return $comment;
    }
}
