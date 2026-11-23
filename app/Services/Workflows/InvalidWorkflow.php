<?php

namespace App\Services\Workflows;

use RuntimeException;

/**
 * Thrown when action YAML can't be read or doesn't follow the action format.
 */
class InvalidWorkflow extends RuntimeException
{
    /**
     * @param  list<string>  $errors  Each error starts with where it is, e.g. "steps[1].with.url: …".
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode("\n", $errors));
    }
}
