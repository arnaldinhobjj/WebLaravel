<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Workspace;
use App\Support\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        $workspace = new Workspace($request->session());
        $currentUser = CurrentUser::get();

        return view('dashboard', [
            'currentUser' => $currentUser,
            'favorites' => $workspace->favorites(),
            'otherModules' => $workspace->otherModules(),
            'actions' => $workspace->actions(),
            'customActions' => $currentUser->actions()->where('show_on_home', true)->get(),
            'mentions' => $workspace->openMentions(),
            'handledMentions' => $workspace->handledMentionCount(),
        ]);
    }

    public function toggleFavorite(Request $request, string $module): RedirectResponse
    {
        abort_unless(isset(Workspace::MODULES[$module]), 404);

        $added = (new Workspace($request->session()))->toggleFavorite($module);
        $name = Workspace::MODULES[$module]['name'];

        return back()->with('status', $added ? "{$name} added to favorites." : "{$name} removed from favorites.");
    }

    public function runAction(Request $request, string $action): RedirectResponse
    {
        abort_unless(isset(Workspace::ACTIONS[$action]), 404);

        (new Workspace($request->session()))->runAction($action);

        if ($prefill = Workspace::ACTIONS[$action]['ticket'] ?? null) {
            return redirect()->route('tickets.create', $prefill);
        }

        return back()->with('status', '“'.Workspace::ACTIONS[$action]['name'].'” started.');
    }

    public function resolveMention(Request $request, int $ticket): RedirectResponse
    {
        abort_unless(isset(Workspace::MENTIONS[$ticket]), 404);

        (new Workspace($request->session()))->handleMention($ticket, 'resolved');

        return back()->with('status', "Mention on #{$ticket} marked as done.");
    }

    public function dismissMention(Request $request, int $ticket): RedirectResponse
    {
        abort_unless(isset(Workspace::MENTIONS[$ticket]), 404);

        (new Workspace($request->session()))->handleMention($ticket, 'dismissed');

        return back()->with('status', "Mention on #{$ticket} dismissed.");
    }

    public function restoreMentions(Request $request): RedirectResponse
    {
        (new Workspace($request->session()))->restoreMentions();

        return back()->with('status', 'All mentions restored.');
    }

    public function switchUser(User $user): RedirectResponse
    {
        CurrentUser::switchTo($user);

        return back()->with('status', "You are now working as {$user->name}.");
    }

    public function resetHome(Request $request): RedirectResponse
    {
        $request->session()->forget(['favorites', 'action_runs', 'handled_mentions']);

        return redirect()->route('dashboard')->with('status', 'Home screen reset to its defaults.');
    }
}
