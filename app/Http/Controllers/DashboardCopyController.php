<?php

namespace App\Http\Controllers;

use App\Models\Dashboard;
use App\Support\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

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

        // Keep the name within the 60-character column: " (my copy)" is 10.
        $name = Str::limit($dashboard->name, 50, '').' (my copy)';
        $copy = $dashboard->load('widgets')->copyFor(CurrentUser::get(), $name);

        return redirect()
            ->route('dashboards.edit', $copy)
            ->with('status', 'This is your own copy. Changes here only affect you.');
    }
}
