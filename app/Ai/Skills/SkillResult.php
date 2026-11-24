<?php

namespace App\Ai\Skills;

/**
 * What a skill gives back to the AI: whether it worked, one sentence a person
 * can read, and the data.
 */
final readonly class SkillResult
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public bool $ok,
        public string $summary,
        public array $data = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function ok(string $summary, array $data = []): self
    {
        return new self(true, $summary, $data);
    }

    public static function fail(string $summary): self
    {
        return new self(false, $summary);
    }

    /**
     * @return array{ok: bool, summary: string, data: array<string, mixed>}
     */
    public function toArray(): array
    {
        return ['ok' => $this->ok, 'summary' => $this->summary, 'data' => $this->data];
    }
}
