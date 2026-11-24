<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A record of one thing an AI did in Fort Vale with a token.
 */
#[Fillable(['token_id', 'tool', 'ok', 'summary'])]
class AiTokenCall extends Model
{
    public const UPDATED_AT = null;

    public function getConnectionName(): ?string
    {
        return config('fortvale.connections.people');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['ok' => 'boolean'];
    }
}
