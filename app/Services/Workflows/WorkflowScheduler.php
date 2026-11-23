<?php

namespace App\Services\Workflows;

use App\Models\User;
use Carbon\CarbonInterface;
use Cron\CronExpression;

/**
 * Runs actions whose `on: schedule` cron matches the current minute. Called
 * every minute by Laravel's scheduler (see routes/console.php).
 */
class WorkflowScheduler
{
    public function __construct(
        private WorkflowRepository $workflows,
        private WorkflowRunner $runner,
    ) {}

    /**
     * @return int How many actions ran.
     */
    public function runDue(CarbonInterface $now): int
    {
        $ran = 0;
        $localTime = $now->copy()->setTimezone(config('app.timezone'));

        foreach ($this->workflows->scheduled() as $workflow) {
            $due = collect($workflow->schedules)->contains(
                fn (string $cron): bool => (new CronExpression($cron))->isDue($localTime->toDateTimeString(), config('app.timezone')),
            );

            if (! $due) {
                continue;
            }

            $owner = $workflow->ownerId !== null ? User::find($workflow->ownerId) : null;
            $this->runner->run($workflow, 'schedule', $owner);
            $ran++;
        }

        return $ran;
    }
}
