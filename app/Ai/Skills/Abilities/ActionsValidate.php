<?php

namespace App\Ai\Skills\Abilities;

use App\Ai\Skills\Skill;
use App\Ai\Skills\SkillResult;
use App\Models\User;
use App\Services\Workflows\InvalidWorkflow;
use App\Services\Workflows\Workflow;
use App\Services\Workflows\WorkflowParser;

class ActionsValidate extends Skill
{
    public function name(): string
    {
        return 'actions.validate';
    }

    public function title(): string
    {
        return 'Check action YAML';
    }

    public function description(): string
    {
        return 'Check a Fort Vale action written in YAML (name, on:, steps) exactly as Fort Vale would, and list every mistake. Use it when writing or fixing actions; it changes nothing.';
    }

    public function properties(): array
    {
        return [
            'yaml' => ['type' => 'string', 'maxLength' => 20000],
            'personal' => ['type' => 'boolean', 'description' => 'Check as a personal action (no secrets). Default false (shared).'],
        ];
    }

    public function required(): array
    {
        return ['yaml'];
    }

    public function allows(User $me): bool
    {
        return $me->hasPermission('actions.view') || $me->hasPermission('shared_actions.view');
    }

    public function run(User $me, array $input): SkillResult
    {
        $source = ! empty($input['personal']) ? Workflow::PERSONAL : Workflow::SHARED;

        try {
            $workflow = app(WorkflowParser::class)->parse($input['yaml'], $source, $source.':check');
        } catch (InvalidWorkflow $invalid) {
            return SkillResult::ok('The YAML has '.count($invalid->errors).' mistakes.', ['valid' => false, 'errors' => $invalid->errors]);
        }

        return SkillResult::ok("Valid: “{$workflow->name}”.", ['valid' => true, 'name' => $workflow->name, 'triggers' => $workflow->triggers(), 'steps' => count($workflow->steps)]);
    }
}
