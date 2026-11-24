<?php

namespace App\Ai\Skills\Abilities;

use App\Ai\Skills\Skill;
use App\Ai\Skills\SkillFailed;
use App\Ai\Skills\SkillResult;
use App\Models\User;
use App\Services\Workflows\Workflow;
use App\Services\Workflows\WorkflowRunner;

class ActionsRun extends Skill
{
    public function name(): string
    {
        return 'actions.run';
    }

    public function title(): string
    {
        return 'Run an action';
    }

    public function description(): string
    {
        return 'Run a Fort Vale action as the person, like pressing its Run button. Use the key from actions.list and give its inputs. Actions can open tickets, send emails or save files, so confirm first. The run is recorded on the person\'s actions page.';
    }

    public function properties(): array
    {
        return [
            'key' => ['type' => 'string', 'maxLength' => 120, 'description' => 'e.g. "shared:request-printer-support".'],
            'inputs' => ['type' => 'object', 'description' => 'Input values by name, as text.'],
        ];
    }

    public function required(): array
    {
        return ['key'];
    }

    public function allows(User $me): bool
    {
        return (new ActionsList)->allows($me);
    }

    public function changesThings(): bool
    {
        return true;
    }

    public function run(User $me, array $input): SkillResult
    {
        $workflow = collect(ActionsList::runnable($me))->first(fn (Workflow $workflow): bool => $workflow->key === $input['key'])
            ?? throw new SkillFailed("No action you may run has the key “{$input['key']}”. actions.list shows them.");

        $inputs = [];
        foreach ((array) ($input['inputs'] ?? []) as $name => $value) {
            if ($value !== null && ! is_scalar($value)) {
                throw new SkillFailed("The input “{$name}” must be text.");
            }
            if ($value !== null && $value !== '') {
                $inputs[(string) $name] = mb_substr(is_bool($value) ? ($value ? 'true' : 'false') : (string) $value, 0, 500);
            }
        }
        foreach ($workflow->inputs() as $name => $definition) {
            if ($definition['required'] && $definition['default'] === null && ! isset($inputs[$name])) {
                throw new SkillFailed("The action needs the input “{$name}”".($definition['description'] ? " ({$definition['description']})" : '').'.');
            }
        }

        $outcome = app(WorkflowRunner::class)->run($workflow, 'run', $me, array_intersect_key($inputs, $workflow->inputs()));
        $steps = array_map(fn (array $step): array => ['step' => $step['name'], 'status' => $step['status'], 'output' => $step['output']], $outcome->run->steps);

        if (! $outcome->run->succeeded()) {
            throw new SkillFailed("“{$workflow->name}” failed: ".json_encode($steps));
        }

        return SkillResult::ok($outcome->message ?? "“{$workflow->name}” ran.", ['action' => $workflow->name, 'message' => $outcome->message, 'opens' => $outcome->redirect, 'steps' => $steps]);
    }
}
