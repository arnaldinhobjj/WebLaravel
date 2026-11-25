<?php

namespace App\Http\Controllers;

use App\Models\Dashboard;
use App\Support\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Makes a personal copy of a dashboard, typically a shared one, to change
 * without affecting everyone else.
 */
class DashboardCopyController extends Controller
{
    public function store(Dashboard $dashboard): RedirectResponse
    {
        abort_unless(Gate::allows('view', $dashboard), 404);
        Gate::authorize('copy', $dashboard);

        $copy = $dashboard->load('widgets')->copyFor(CurrentUser::get(), $dashboard->name.' (my copy)');

        return redirect()
            ->route('dashboards.edit', $copy)
            ->with('status', 'This is your own copy. Changes here only affect you.');
    }
}
