<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * One AI a person can chat with: a local one (Ollama, LM Studio…) or a cloud
 * one (OpenAI, Claude, Gemini…). The browser talks to it directly with the
 * key, which is stored encrypted and only ever handed to its owner's browser.
 */
#[Fillable(['user_id', 'name', 'provider', 'base_url', 'model', 'api_key'])]
#[Hidden(['api_key'])]
class AiConnection extends Model
{
    /** How the browser talks to it. */
    public const PROVIDERS = [
        'openai' => 'OpenAI-compatible',
        'anthropic' => 'Anthropic (Claude)',
    ];

    /** Ready-made addresses for the connection form. */
    public const PRESETS = [
        'Ollama (this computer)' => ['openai', 'http://localhost:11434/v1'],
        'LM Studio (this computer)' => ['openai', 'http://localhost:1234/v1'],
        'OpenAI' => ['openai', 'https://api.openai.com/v1'],
        'Anthropic Claude' => ['anthropic', 'https://api.anthropic.com/v1'],
        'Google Gemini' => ['openai', 'https://generativelanguage.googleapis.com/v1beta/openai'],
        'OpenRouter' => ['openai', 'https://openrouter.ai/api/v1'],
        'Groq' => ['openai', 'https://api.groq.com/openai/v1'],
    ];

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
}
