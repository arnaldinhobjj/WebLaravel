<?php

namespace App\Services\Ai;

use RuntimeException;

/**
 * The AI answered with an error status. Keeps the status and body so callers
 * can react to specific refusals (e.g. a model that can't use tools).
 */
class AiProviderError extends RuntimeException
{
    public function __construct(string $message, public readonly int $status, public readonly string $body)
    {
        parent::__construct($message);
    }

    /** The model refused the tool definitions, as /ai's chat detects it. */
    public function rejectsTools(): bool
    {
        return $this->status >= 400 && $this->status < 500 && stripos($this->body, 'tool') !== false;
    }
}
