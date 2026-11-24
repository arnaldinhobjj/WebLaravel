<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One message in a saved chat: from the person (`user`) or the AI (`assistant`).
 */
#[Fillable(['conversation_id', 'role', 'content', 'model'])]
class AiMessage extends Model
{
    public const UPDATED_AT = null;

    public function getConnectionName(): ?string
    {
        return config('fortvale.connections.people');
    }
}
