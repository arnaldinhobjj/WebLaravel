<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

#[Fillable(['name', 'username', 'email', 'job_title', 'theme', 'password'])]
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
    public const RESERVED_USERNAMES = ['ticket', 'favorites', 'actions', 'mentions', 'profile', 'settings', 'up', 'storage', 'css', 'js'];

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
        $base = Str::limit(Str::slug($name, '.'), 26, '') ?: 'user';

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
        ];
    }

    /**
     * @return HasMany<Action, $this>
     */
    public function actions(): HasMany
    {
        return $this->hasMany(Action::class)->orderBy('name');
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
