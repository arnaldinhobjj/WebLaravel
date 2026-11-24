<?php

namespace App\Http\Controllers;

use App\Models\Action;
use App\Models\User;
use App\Services\Workflows\Links;
use App\Services\Workflows\Workflow;
use App\Services\Workflows\WorkflowRepository;
use App\Services\Workflows\WorkflowRunner;
use App\Support\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The Run button: asks for inputs if the action has any, runs it, then sends
 * the person to the action's page or back with a message.
 */
class ActionRunController extends Controller
{
    public function __construct(
        private WorkflowRepository $workflows,
        private WorkflowRunner $runner,
    ) {}

    public function createShared(string $slug): View
    {
        return $this->inputsForm($this->sharedOrFail($slug, manual: true), route('actions.shared.run', $slug));
    }

    public function storeShared(Request $request, string $slug): RedirectResponse
    {
        return $this->run($request, $this->sharedOrFail($slug, manual: true));
    }

    public function create(User $user, Action $action): View
    {
        $workflow = $action->workflow();
        abort_unless($workflow->canRunManually(), 404);

        return $this->inputsForm($workflow, route('actions.custom.run', [$user, $action]));
    }

    public function store(Request $request, User $user, Action $action): RedirectResponse
    {
        $workflow = $action->workflow();
        abort_unless($workflow->canRunManually(), 404);

        return $this->run($request, $workflow);
    }

    private function sharedOrFail(string $slug, bool $manual = false): Workflow
    {
        $workflow = $this->workflows->findShared($slug);
        abort_if($workflow === null || ($manual && ! $workflow->canRunManually()), 404);

        return $workflow;
    }

    private function inputsForm(Workflow $workflow, string $action): View
    {
        return view('actions.run', ['workflow' => $workflow, 'formAction' => $action]);
    }

    private function run(Request $request, Workflow $workflow): RedirectResponse
    {
        $rules = [];
        foreach ($workflow->inputs() as $name => $input) {
            $rules["inputs.{$name}"] = [$input['required'] && $input['default'] === null ? 'required' : 'nullable', 'string', 'max:500'];
        }

        $inputs = array_filter(
            (array) ($request->validate($rules)['inputs'] ?? []),
            fn (mixed $value): bool => $value !== null && $value !== '',
        );

        $person = CurrentUser::get();
        $outcome = $this->runner->run($workflow, 'run', $person, $inputs);

        if ($outcome->redirect !== null) {
            return Links::isWebAddress($outcome->redirect) && ! str_starts_with($outcome->redirect, url('/'))
                ? redirect()->away($outcome->redirect)
                : redirect()->to($outcome->redirect);
        }

        $status = $outcome->run->succeeded()
            ? ($outcome->message ?? "“{$workflow->name}” ran successfully.")
            : "“{$workflow->name}” failed. See the run log on My actions.";

        $back = $workflow->inputs() === [] ? url()->previous(route('dashboard')) : route('actions.index', $person);

        return redirect()->to($back)->with('status', $status);
    }
}
