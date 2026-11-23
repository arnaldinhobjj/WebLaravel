<?php

namespace App\Models;

use Database\Factories\ActionRunFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One run of an action and the result of each of its steps.
 */
class ActionRun extends Model
{
    /** @use HasFactory<ActionRunFactory> */
    use HasFactory;

    public const SUCCESS = 'success';

    public const FAILED = 'failed';

    protected $fillable = [
        'action_key', 'action_name', 'source', 'action_id', 'owner_id', 'user_id',
        'trigger', 'event', 'ticket_id', 'status', 'message', 'steps', 'started_at', 'finished_at',
    ];

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
            'steps' => 'array',
            'ticket_id' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
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
     * Runs a person may see: ones they started, and runs of their own actions.
     *
     * @param  Builder<ActionRun>  $query
     */
    public function scopeVisibleTo(Builder $query, User $person): void
    {
        $query->where(fn (Builder $query) => $query->where('user_id', $person->id)->orWhere('owner_id', $person->id));
    }

    public function succeeded(): bool
    {
        return $this->status === self::SUCCESS;
    }
}
