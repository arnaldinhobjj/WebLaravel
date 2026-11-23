<?php

namespace App\Services\Workflows\Steps;

use App\Services\Workflows\RunContext;

/**
 * One kind of step an action can use, e.g. `ticket/create`.
 */
interface Step
{
    /**
     * Checks the step's `with:` values before the action is saved.
     *
     * @param  array<string, mixed>  $with
     * @return list<string> Errors, each prefixed with `$path`.
     */
    public function validate(array $with, string $path): array;

    /**
     * Runs the step with placeholders already filled in.
     *
     * @param  array<string, mixed>  $with
     * @return array<string, mixed> Outputs, available later as `${{ steps.<id>.<name> }}`.
     *
     * @throws StepFailed
     */
    public function run(array $with, RunContext $context): array;

    /**
     * Whether the step ends the run by sending the person to a page (Run button only).
     */
    public function redirects(): bool;
}
