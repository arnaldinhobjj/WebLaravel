<?php

namespace App\Services\Workflows;

use App\Models\Action;
use App\Models\ActionRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Workflows\Steps\StepFailed;
use App\Services\Workflows\Steps\StepRegistry;
use Throwable;

/**
 * Runs an action's steps in order, stops at the first failing step, and
 * records the run with each step's result.
 */
class WorkflowRunner
{
    /** Nesting depth; ticket changes made while > 0 don't trigger more actions. */
    private static int $depth = 0;

    public function __construct(private StepRegistry $steps) {}

    public static function isRunning(): bool
    {
        return self::$depth > 0;
    }

    /**
     * @param  'run'|'ticket'|'schedule'  $trigger
     * @param  array<string, string>  $inputs  Already validated against the action's inputs.
     */
    public function run(Workflow $workflow, string $trigger, ?User $actor, array $inputs = [], ?Ticket $ticket = null, ?string $event = null): RunOutcome
    {
        $defaults = array_map(fn (array $input): string => $input['default'] ?? '', $workflow->inputs());
        $secrets = $workflow->isShared() ? (array) config('fortvale.actions.secrets', []) : [];
        $context = new RunContext($workflow, $trigger, $actor, $ticket, array_merge($defaults, $inputs), $secrets);

        $startedAt = now();
        $log = [];
        $status = ActionRun::SUCCESS;

        self::$depth++;

        try {
            foreach ($workflow->steps as $step) {
                $handler = $this->steps->get($step['uses']);

                if ($handler->redirects() && $trigger !== 'run') {
                    $log[] = ['name' => $step['name'], 'uses' => $step['uses'], 'status' => 'skipped', 'output' => 'Only runs from the Run button.'];

                    continue;
                }

                $timer = hrtime(true);

                try {
                    $keep = $step['uses'] === 'email/send' ? ['recipient'] : [];
                    $output = $handler->run(Expressions::render($step['with'], $context->values(), $keep), $context);

                    if ($step['id'] !== null) {
                        $context->stepOutputs[$step['id']] = $output;
                    }

                    $log[] = $this->entry($step, 'success', $output, $timer);
                } catch (StepFailed $failure) {
                    $log[] = $this->entry($step, 'failed', $failure->getMessage(), $timer);
                    $status = ActionRun::FAILED;

                    break;
                } catch (Throwable $exception) {
                    report($exception);
                    $log[] = $this->entry($step, 'failed', 'Unexpected error: '.$exception->getMessage(), $timer);
                    $status = ActionRun::FAILED;

                    break;
                }
            }
        } finally {
            self::$depth--;
        }

        $suffix = str($workflow->key)->after(':')->toString();
        $actionId = ! $workflow->isShared() && ctype_digit($suffix) ? (int) $suffix : null;

        $run = ActionRun::create([
            'action_key' => $workflow->key,
            'action_name' => $workflow->name,
            'source' => $workflow->source,
            'action_id' => $actionId,
            'owner_id' => $workflow->ownerId,
            'user_id' => $actor?->id,
            'trigger' => $trigger,
            'event' => $event,
            'ticket_id' => $ticket?->id,
            'status' => $status,
            'message' => $context->message,
            'steps' => $this->mask($log, $secrets),
            'started_at' => $startedAt,
            'finished_at' => now(),
        ]);

        if ($actionId !== null) {
            Action::whereKey($actionId)->update(['last_run_at' => $startedAt]);
        }

        return new RunOutcome($run, $status === ActionRun::SUCCESS ? $context->redirect : null, $context->message);
    }

    /**
     * @param  array{name: string, uses: string}  $step
     */
    private function entry(array $step, string $status, mixed $output, int|float $timer): array
    {
        return [
            'name' => $step['name'],
            'uses' => $step['uses'],
            'status' => $status,
            'output' => $output,
            'ms' => (int) round((hrtime(true) - $timer) / 1e6),
        ];
    }

    /**
     * Hides secret values wherever they appear in the log.
     *
     * @param  list<array<string, mixed>>  $log
     * @param  array<string, string>  $secrets
     * @return list<array<string, mixed>>
     */
    private function mask(array $log, array $secrets): array
    {
        $values = array_filter(array_map('strval', $secrets), fn (string $secret): bool => strlen($secret) >= 4);

        if ($values === []) {
            return $log;
        }

        return json_decode(str_replace(
            array_map(fn (string $secret): string => trim((string) json_encode($secret), '"'), $values),
            '***',
            (string) json_encode($log),
        ), true);
    }
}
