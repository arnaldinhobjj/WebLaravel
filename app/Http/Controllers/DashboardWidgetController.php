<?php

namespace App\Http\Controllers;

use App\Enums\DashboardWidgetType;
use App\Enums\WidgetScope;
use App\Http\Requests\StoreDashboardWidgetRequest;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Adding, moving and removing the widgets of a dashboard.
 */
class DashboardWidgetController extends Controller
{
    public function store(StoreDashboardWidgetRequest $request, Dashboard $dashboard): RedirectResponse
    {
        $this->authorizeChanges($dashboard);

        $widget = $dashboard->addWidget($request->enum('type', DashboardWidgetType::class), $request->enum('scope', WidgetScope::class));

        return redirect()->route('dashboards.edit', $dashboard)->with('status', "“{$widget->type->label()}” added.");
    }

    /**
     * Moves a widget one place up or down.
     */
    public function update(Request $request, Dashboard $dashboard, DashboardWidget $widget): RedirectResponse
    {
        $this->authorizeChanges($dashboard);
        $direction = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]])['direction'];

        $widgets = $dashboard->widgets()->get()->values();
        $index = $widgets->search(fn (DashboardWidget $w): bool => $w->id === $widget->id);
        $swapWith = $direction === 'up' ? $index - 1 : $index + 1;

        if ($swapWith >= 0 && $swapWith < $widgets->count()) {
            $order = $widgets->all();
            [$order[$index], $order[$swapWith]] = [$order[$swapWith], $order[$index]];

            $dashboard->getConnection()->transaction(function () use ($order): void {
                foreach ($order as $position => $item) {
                    $item->update(['position' => $position + 1]);
                }
            });
        }

        return redirect()->route('dashboards.edit', $dashboard);
    }

    public function destroy(Dashboard $dashboard, DashboardWidget $widget): RedirectResponse
    {
        $this->authorizeChanges($dashboard);

        $widget->delete();

        return redirect()->route('dashboards.edit', $dashboard)->with('status', "“{$widget->type->label()}” removed.");
    }

    private function authorizeChanges(Dashboard $dashboard): void
    {
        abort_unless(Gate::allows('view', $dashboard), 404);
        Gate::authorize('update', $dashboard);
    }
}
