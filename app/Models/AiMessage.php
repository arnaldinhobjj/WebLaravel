<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One message in a saved chat: from the person (`user`), the AI
 * (`assistant`, with `meta.calls` when it asked to use skills), or a skill's
 * result (`tool`, with `meta.name`, `meta.call_id`, `meta.ok`).
 */
#[Fillable(['conversation_id', 'role', 'content', 'model', 'meta'])]
class AiMessage extends Model
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
        return ['meta' => 'array'];
    }
}
