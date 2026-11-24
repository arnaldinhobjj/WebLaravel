<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A token that lets someone's AI use Fort Vale as them (MCP). Only a hash is
 * stored; the token itself is shown once when it's created.
 */
#[Fillable(['user_id', 'name', 'token_hash', 'last_used_at'])]
#[Hidden(['token_hash'])]
class AiToken extends Model
{
    public const PREFIX = 'fv_';

    public function getConnectionName(): ?string
    {
        return config('fortvale.connections.people');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }

    /**
     * Creates a token and returns it with its plain text (shown once).
     *
     * @return array{token: AiToken, plain: string}
     */
    public static function issue(User $person, string $name): array
    {
        $plain = self::PREFIX.Str::random(40);
        $token = static::create(['user_id' => $person->id, 'name' => $name, 'token_hash' => hash('sha256', $plain)]);

        return ['token' => $token, 'plain' => $plain];
    }

    public static function findByPlain(string $plain): ?self
    {
        return static::where('token_hash', hash('sha256', $plain))->first();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<AiTokenCall, $this>
     */
    public function calls(): HasMany
    {
        return $this->hasMany(AiTokenCall::class, 'token_id')->latest('id');
    }
}
