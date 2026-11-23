<?php

namespace App\Http\Controllers;

use App\Models\Action;
use App\Models\ActionRun;
use App\Models\User;
use App\Services\Workflows\ActionTemplates;
use App\Services\Workflows\InvalidWorkflow;
use App\Services\Workflows\Workflow;
use App\Services\Workflows\WorkflowParser;
use App\Services\Workflows\WorkflowRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The actions page: shared actions from `actions/*.yml`, the person's own
 * YAML actions, and recent runs.
 */
class ActionController extends Controller
{
    public function __construct(private WorkflowParser $parser) {}

    public function index(User $user, WorkflowRepository $workflows): View
    {
        return view('actions.index', [
            'user' => $user,
            'shared' => $workflows->sharedWithErrors(),
            'actions' => $user->actions()->get(),
            'runs' => ActionRun::visibleTo($user)->latest('started_at')->latest('id')->limit(20)->get(),
        ]);
    }

    public function create(Request $request, User $user): View
    {
        $template = ActionTemplates::TEMPLATES[(string) $request->query('template')] ?? ActionTemplates::TEMPLATES['page'];

        return view('actions.form', [
            'user' => $user,
            'action' => new Action,
            'yaml' => $template['yaml'],
        ]);
    }

    public function store(Request $request, User $user): RedirectResponse
    {
        $workflow = $this->validatedWorkflow($request, $user, 'personal:new');
        $action = $user->actions()->make()->fillFromWorkflow($workflow);
        $action->save();

        return redirect()
            ->route('actions.index', $user)
            ->with('status', "“{$action->name}” created.");
    }

    public function edit(User $user, Action $action): View
    {
        return view('actions.form', ['user' => $user, 'action' => $action, 'yaml' => $action->yaml]);
    }

    public function update(Request $request, User $user, Action $action): RedirectResponse
    {
        $action->fillFromWorkflow($this->validatedWorkflow($request, $user, $action->key()))->save();

        return redirect()
            ->route('actions.index', $user)
            ->with('status', "“{$action->name}” saved.");
    }

    public function destroy(User $user, Action $action): RedirectResponse
    {
        $action->delete();

        return redirect()
            ->route('actions.index', $user)
            ->with('status', "“{$action->name}” deleted.");
    }

    /**
     * Parses the submitted YAML; on mistakes, returns to the editor with every
     * error listed and the text kept.
     */
    private function validatedWorkflow(Request $request, User $user, string $key): Workflow
    {
        $request->validate(['yaml' => ['required', 'string', 'max:20000']]);

        try {
            return $this->parser->parse($request->string('yaml')->toString(), Workflow::PERSONAL, $key, $user->id);
        } catch (InvalidWorkflow $invalid) {
            throw ValidationException::withMessages(['yaml' => $invalid->errors]);
        }
    }
}
