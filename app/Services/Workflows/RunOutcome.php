<?php

namespace App\Services\Workflows;

use App\Models\ActionRun;

/**
 * The recorded run, plus where to send the person and what to tell them.
 */
final readonly class RunOutcome
{
    public function __construct(
        public ActionRun $run,
        public ?string $redirect,
        public ?string $message,
    ) {}
}
