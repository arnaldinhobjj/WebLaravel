<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A saved chat with your AI. Only its owner can see it.
 */
#[Fillable(['user_id', 'title'])]
class AiConversation extends Model
{
    public function getConnectionName(): ?string
    {
        return config('fortvale.connections.people');
    }

    /**
     * @return HasMany<AiMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(AiMessage::class, 'conversation_id')->orderBy('id');
    }
}
