<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * One person's connection to their own local AI. The browser uses it to talk
 * to the AI directly; the API key is stored encrypted.
 */
#[Fillable(['user_id', 'base_url', 'model', 'api_key', 'system_prompt'])]
#[Hidden(['api_key'])]
class AiSetting extends Model
{
    public const DEFAULT_BASE_URL = 'http://localhost:11434/v1';

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
        return ['api_key' => 'encrypted'];
    }

    public static function for(User $person): self
    {
        return static::firstOrNew(['user_id' => $person->id], ['base_url' => self::DEFAULT_BASE_URL]);
    }
}
