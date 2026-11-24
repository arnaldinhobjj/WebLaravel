<?php

namespace App\Mcp\Tools;

use App\Models\Action;
use App\Models\User;
use App\Services\Workflows\Workflow;
use App\Services\Workflows\WorkflowRepository;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('list_actions')]
#[Description('Lists the Fort Vale actions the person may run from a Run button, with their inputs. Run one with run_action and its key.')]
class ListActions extends FortValeTool
{
    protected function permissions(): array
    {
        return [];
    }

    public function shouldRegister(): bool
    {
        $me = auth()->user();

        return $me instanceof User && ($me->hasPermission('shared_actions.run') || $me->hasPermission('actions.run'));
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function perform(User $me, Request $request): Response
    {
        return Response::json(['actions' => array_map(fn (Workflow $workflow): array => [
            'key' => $workflow->key,
            'name' => $workflow->name,
            'description' => $workflow->description,
            'inputs' => $workflow->inputs(),
        ], self::runnable($me))]);
    }

    /**
     * Run-button actions this person may run: shared ones and their own.
     *
     * @return list<Workflow>
     */
    public static function runnable(User $me): array
    {
        $shared = $me->hasPermission('shared_actions.run')
            ? app(WorkflowRepository::class)->shared()->filter(fn (Workflow $workflow): bool => $workflow->canRunManually())->values()->all()
            : [];
        $personal = $me->hasPermission('actions.run')
            ? $me->actions()->get()->map(fn (Action $action): Workflow => $action->workflow())->filter(fn (Workflow $workflow): bool => $workflow->canRunManually())->values()->all()
            : [];

        return [...$shared, ...$personal];
    }
}
