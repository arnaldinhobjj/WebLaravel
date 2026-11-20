<?php

namespace App\Http\Controllers;

use App\Enums\ActionType;
use App\Http\Requests\SaveActionRequest;
use App\Models\Action;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ActionController extends Controller
{
    public function index(User $user): View
    {
        return view('actions.index', [
            'user' => $user,
            'actions' => $user->actions()->get(),
        ]);
    }

    public function create(User $user): View
    {
        return view('actions.form', [
            'user' => $user,
            'action' => new Action(['type' => ActionType::Link, 'show_on_home' => true]),
        ]);
    }

    public function store(SaveActionRequest $request, User $user): RedirectResponse
    {
        $action = $user->actions()->create($request->actionAttributes());

        return redirect()
            ->route('actions.index', $user)
            ->with('status', "“{$action->name}” created.");
    }

    public function edit(User $user, Action $action): View
    {
        return view('actions.form', ['user' => $user, 'action' => $action]);
    }

    public function update(SaveActionRequest $request, User $user, Action $action): RedirectResponse
    {
        $action->update($request->actionAttributes());

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
}
