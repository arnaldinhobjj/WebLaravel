<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One person's AI preferences: instructions for their AIs and the skills they
 * switched off. Their AIs themselves are AiConnection rows.
 */
#[Fillable(['user_id', 'system_prompt', 'disabled_skills'])]
class AiSetting extends Model
{
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    public function getConnectionName(): ?string
    {
        return config('fortvale.connections.people');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['disabled_skills' => 'array'];
    }

    public static function for(User $person): self
    {
        return static::firstOrNew(['user_id' => $person->id]);
    }
}
