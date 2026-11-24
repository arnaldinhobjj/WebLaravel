<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

#[Fillable(['name', 'username', 'email', 'job_title', 'department_id', 'theme', 'email_preferences', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** Theme choices on the settings page. */
    public const THEMES = ['system' => 'Match system', 'light' => 'Light', 'dark' => 'Dark'];

    /**
     * Usernames that would clash with top-level routes such as /ticket.
     */
    public const RESERVED_USERNAMES = ['ticket', 'favorites', 'actions', 'mentions', 'profile', 'settings', 'up', 'storage', 'css', 'js', 'login', 'logout', 'tags', 'email', 'files', 'my-files', 'workspace'];

    /**
     * Always the people connection (MySQL by default), even when loaded through
     * a ticket: Eloquent otherwise reuses the parent model's connection.
     */
    public function getConnectionName(): ?string
    {
        return config('fortvale.connections.people');
    }

    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            $user->username ??= static::uniqueUsernameFor($user->name);
        });
    }

    /**
     * A free username based on a person's name, such as "alex.morgan" or "alex.morgan2".
     */
    public static function uniqueUsernameFor(string $name): string
    {
        $base = rtrim(Str::limit(Str::slug($name, '.'), 26, ''), '.') ?: 'user';

        if (in_array($base, self::RESERVED_USERNAMES, true)) {
            $base .= '.user';
        }

        $username = $base;
        $suffix = 2;

        while (static::where('username', $username)->exists()) {
            $username = $base.$suffix++;
        }

        return $username;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'email_preferences' => 'array',
        ];
    }

    /** Ticket emails a person can turn off in Settings. */
    public const EMAIL_PREFERENCES = [
        'created' => 'A ticket is opened and assigned to me',
        'assigned' => 'A ticket is assigned to me',
        'replied' => 'Someone replies on my ticket',
        'status_changed' => 'The status of my ticket changes',
    ];

    /**
     * Whether this person wants the email for a ticket event (on unless turned off).
     */
    public function wantsEmail(string $event): bool
    {
        return (bool) ($this->email_preferences[$event] ?? true);
    }

    /**
     * @return HasMany<Action, $this>
     */
    public function actions(): HasMany
    {
        return $this->hasMany(Action::class)->orderBy('name');
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * Tags given to this person directly (on top of their department's).
     *
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)->orderBy('name');
    }

    /**
     * Every tag this person has: their own plus their department's.
     *
     * @return Collection<int, Tag>
     */
    public function allTags(): Collection
    {
        return $this->tags->concat($this->department?->tags ?? [])->unique('id')->sortBy('name')->values();
    }

    /** @var list<string>|null Worked out once per loaded person. */
    private ?array $permissionCache = null;

    /**
     * Everything this person may do ("page.ability"): the Everyone tag plus
     * their own and their department's tags.
     *
     * @return list<string>
     */
    public function permissions(): array
    {
        return $this->permissionCache ??= $this->allTags()
            ->push(Tag::everyone())
            ->filter()
            ->flatMap(fn (Tag $tag): array => $tag->permissions ?? [])
            ->unique()
            ->values()
            ->all();
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    public function assignedTickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'assignee_id');
    }

    public function requestedTickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'requester_id');
    }

    public function initials(): string
    {
        return collect(explode(' ', $this->name))
            ->filter()
            ->map(fn (string $part) => mb_strtoupper(mb_substr($part, 0, 1)))
            ->take(2)
            ->implode('');
    }
}
