<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Background AI work started by an action (`ai/report`): the AI writes in a
 * saved chat on the server, the report is saved in the person's My files,
 * and a badge on every page shows how it's going. A person's tasks run one
 * at a time, in the order they were started.
 */
#[Fillable(['user_id', 'connection_id', 'conversation_id', 'title', 'prompt', 'status', 'file_id', 'folder_id', 'error', 'started_at', 'finished_at', 'seen_at', 'dismissed_at'])]
class AiTask extends Model
{
    public const QUEUED = 'queued';

    public const WORKING = 'working';

    /** Stop was pressed while it ran: the job finishes writing the chat, then marks it cancelled. */
    public const STOPPING = 'stopping';

    public const DONE = 'done';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

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
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'seen_at' => 'datetime',
            'dismissed_at' => 'datetime',
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
     * @return BelongsTo<AiConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(AiConnection::class, 'connection_id');
    }

    /**
     * @return BelongsTo<AiConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::QUEUED, self::WORKING, self::STOPPING], true);
    }

    /**
     * The person's tasks that still have a badge: running, waiting, or
     * finished and not closed yet.
     *
     * @param  Builder<self>  $query
     */
    public function scopeWithBadge(Builder $query, User $person): void
    {
        $query->where('user_id', $person->id)->whereNull('dismissed_at')->orderBy('id');
    }

    /**
     * What the badge shows.
     *
     * @return array{id: int, title: string, status: string, error: ?string, seen: bool, chat_url: ?string, file_url: ?string, seen_url: string, dismiss_url: string}
     */
    public function toBadge(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'status' => $this->status,
            'error' => $this->error,
            'seen' => $this->seen_at !== null,
            'chat_url' => $this->conversation_id ? route('ai.index', ['c' => $this->conversation_id]) : null,
            'file_url' => $this->folder_id ? route('files.folder', $this->folder_id).($this->file_id ? '#file-'.$this->file_id : '') : null,
            'seen_url' => route('ai.tasks.seen', $this),
            'dismiss_url' => route('ai.tasks.dismiss', $this),
        ];
    }
}
