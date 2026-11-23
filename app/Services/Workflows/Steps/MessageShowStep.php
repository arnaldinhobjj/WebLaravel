<?php

namespace App\Services\Workflows\Steps;

use App\Services\Workflows\RunContext;

/**
 * `message/show`: the message shown after a Run-button run.
 */
class MessageShowStep extends BaseStep
{
    protected function keys(): array
    {
        return ['text' => true];
    }

    protected function checks(array $with, string $path): array
    {
        return $this->plainText($with, 'text', $path, 300);
    }

    public function run(array $with, RunContext $context): array
    {
        $context->message = mb_substr((string) $with['text'], 0, 300);

        return [];
    }
}
