<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Services\Workflows\Workflow;
use App\Services\Workflows\WorkflowRunner;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('run_action')]
#[Description('Runs a Fort Vale action as the person, like pressing its Run button. Use the key from list_actions and give any inputs it needs. The run is recorded on the person\'s actions page.')]
class RunAction extends FortValeTool
{
    protected function permissions(): array
    {
        return [];
    }

    public function shouldRegister(): bool
    {
        return (new ListActions)->shouldRegister();
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'key' => $schema->string()->description('The action key from list_actions, e.g. "shared:request-printer-support".')->required(),
            'inputs' => $schema->object()->description('Input values by name.'),
        ];
    }

    protected function perform(User $me, Request $request): Response
    {
        $data = $request->validate(['key' => ['required', 'string', 'max:120'], 'inputs' => ['nullable', 'array']]);
        $workflow = collect(ListActions::runnable($me))->first(fn (Workflow $workflow): bool => $workflow->key === $data['key']);

        if ($workflow === null) {
            return Response::error("No action you may run has the key “{$data['key']}”. Use list_actions to see them.");
        }

        // Inputs are text; numbers and true/false are accepted and turned into text.
        $inputs = [];
        foreach ($data['inputs'] ?? [] as $name => $value) {
            if (! is_scalar($value) && $value !== null) {
                return Response::error("The input “{$name}” must be text.");
            }
            if ($value !== null && $value !== '') {
                $inputs[(string) $name] = mb_substr(is_bool($value) ? ($value ? 'true' : 'false') : (string) $value, 0, 500);
            }
        }
        foreach ($workflow->inputs() as $name => $input) {
            if ($input['required'] && $input['default'] === null && ! isset($inputs[$name])) {
                return Response::error("The action needs the input “{$name}”".($input['description'] ? " ({$input['description']})" : '').'.');
            }
        }

        $outcome = app(WorkflowRunner::class)->run($workflow, 'run', $me, array_intersect_key($inputs, $workflow->inputs()));

        $result = [
            'action' => $workflow->name,
            'succeeded' => $outcome->run->succeeded(),
            'message' => $outcome->message,
            'opens' => $outcome->redirect,
            'steps' => array_map(fn (array $step): array => ['step' => $step['name'], 'status' => $step['status'], 'output' => $step['output']], $outcome->run->steps),
        ];

        return $outcome->run->succeeded() ? Response::json($result) : Response::error('The action failed: '.json_encode($result['steps']));
    }
}
