<?php

namespace App\Http\Controllers;

use App\Enums\DashboardWidgetType;
use App\Enums\WidgetScope;
use App\Http\Requests\ReorderDashboardWidgetsRequest;
use App\Http\Requests\StoreDashboardWidgetRequest;
use App\Http\Requests\UpdateDashboardWidgetRequest;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Services\DashboardTables\TableSources;
use App\Support\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * The customize screen's changes to a dashboard's widgets: add (at a place),
 * change settings, reorder (drag and drop) and remove.
 */
class DashboardWidgetController extends Controller
{
    public function store(StoreDashboardWidgetRequest $request, Dashboard $dashboard, TableSources $tables): RedirectResponse
    {
        $type = $request->enum('type', DashboardWidgetType::class);
        $scope = $request->enum('scope', WidgetScope::class) ?? ($dashboard->isShared() ? WidgetScope::Everyone : WidgetScope::Mine);

        $settings = null;
        if ($type === DashboardWidgetType::Table) {
            $editor = CurrentUser::get();
            $source = $tables->find($request->input('source')) ?? collect($tables->availableTo($editor))->first();
            $settings = $source->defaultSettings($editor);
        }

        $widget = $dashboard->addWidget($type, $scope, $request->integer('position') ?: null, $settings);

        // A new table opens its settings straight away, to pick columns and filters.
        return redirect()
            ->route('dashboards.edit', array_filter([$dashboard, 'widget' => $type === DashboardWidgetType::Table ? $widget->id : null]))
            ->with('status', "“{$widget->displayTitle()}” added.");
    }

    public function update(UpdateDashboardWidgetRequest $request, Dashboard $dashboard, DashboardWidget $widget): RedirectResponse
    {
        $data = $request->validated();
        $widget->fill(array_intersect_key($data, array_flip(['title', 'size', 'scope'])));

        if ($widget->type === DashboardWidgetType::Table && $request->has('source')) {
            $widget->settings = [
                'source' => $data['source'],
                'columns' => array_values($data['columns']),
                'filters' => array_map(fn (?string $value): string => (string) $value, $data['filters'] ?? []),
                'limit' => (int) ($data['limit'] ?? $widget->settings['limit'] ?? 10),
            ];
        }

        $widget->save();

        return redirect()->route('dashboards.edit', $dashboard)->with('status', "“{$widget->displayTitle()}” saved.");
    }

    /**
     * Saves the order after a drag and drop. Answers JSON for the page's
     * script, or goes back to the customize screen.
     */
    public function reorder(ReorderDashboardWidgetsRequest $request, Dashboard $dashboard): JsonResponse|RedirectResponse
    {
        $dashboard->reorderWidgets($request->order());

        return $request->expectsJson()
            ? response()->json(['saved' => true])
            : redirect()->route('dashboards.edit', $dashboard)->with('status', 'Order saved.');
    }

    public function destroy(Dashboard $dashboard, DashboardWidget $widget): RedirectResponse
    {
        abort_unless(Gate::allows('view', $dashboard), 404);
        Gate::authorize('update', $dashboard);

        $widget->delete();

        return redirect()->route('dashboards.edit', $dashboard)->with('status', "“{$widget->displayTitle()}” removed.");
    }
}
