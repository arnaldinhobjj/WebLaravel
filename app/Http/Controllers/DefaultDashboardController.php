<?php

namespace App\Http\Controllers;

use App\Models\Dashboard;
use App\Support\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Chooses which dashboard /dashboard opens for the current person.
 */
class DefaultDashboardController extends Controller
{
    public function store(Dashboard $dashboard): RedirectResponse
    {
        abort_unless(Gate::allows('view', $dashboard), 404);

        CurrentUser::get()->forceFill(['default_dashboard_id' => $dashboard->id])->save();

        return redirect()->route('dashboards.index')->with('status', "“{$dashboard->name}” now opens at /dashboard.");
    }
}
