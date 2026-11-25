<?php

namespace App\Http\Controllers;

use App\Enums\DashboardVisibility;
use App\Enums\DashboardWidgetType;
use App\Http\Requests\SaveDashboardRequest;
use App\Models\Dashboard;
use App\Models\User;
use App\Services\DashboardTables\TableSource;
use App\Services\DashboardTables\TableSources;
use App\Services\DashboardWidgets;
use App\Support\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * /dashboard: shared dashboards everyone sees, plus each person's own.
 */
class DashboardController extends Controller
{
    /**
     * The person's default dashboard, else their first own one, else the
     * first shared one; an empty state when there are none yet.
     */
    public function index(): View
    {
        $me = CurrentUser::get();
        $dashboards = $this->visibleDashboards($me);

        $dashboard = $dashboards->firstWhere('id', $me->default_dashboard_id)
            ?? $dashboards->first(fn (Dashboard $dashboard): bool => $dashboard->isOwnedBy($me))
            ?? $dashboards->first();

        if (! $dashboard) {
            return view('dashboards.empty', ['me' => $me]);
        }

        return $this->render($dashboard, $me, $dashboards);
    }

    public function show(Dashboard $dashboard): View
    {
        $me = CurrentUser::get();

        // Someone else's personal dashboard looks the same as a missing one.
        abort_unless(Gate::allows('view', $dashboard), 404);

        return $this->render($dashboard, $me, $this->visibleDashboards($me));
    }

    public function create(): View
    {
        return view('dashboards.create', ['dashboard' => new Dashboard]);
    }

    public function store(SaveDashboardRequest $request): RedirectResponse
    {
        $dashboard = new Dashboard($request->safe()->only(['name', 'visibility']));
        $dashboard->user_id = CurrentUser::get()->id;
        $dashboard->save();

        if ($request->input('start_with', 'recommended') === 'recommended') {
            $dashboard->addRecommendedWidgets();
        }

        return redirect()
            ->route('dashboards.edit', $dashboard)
            ->with('status', "“{$dashboard->name}” created. Add, remove or reorder its widgets here.");
    }

    /**
     * The customize screen: the widgets as they look, to drag into order,
     * resize, set up or remove; a library to drag new ones from; and the
     * dashboard's name and who can see it.
     */
    public function edit(Request $request, Dashboard $dashboard, TableSources $tables): View
    {
        $this->authorizeOrHide('update', $dashboard);

        $me = CurrentUser::get();
        $dashboard->load('widgets');
        $widgets = new DashboardWidgets($me);
        $available = $tables->availableTo($me);

        return view('dashboards.edit', [
            'dashboard' => $dashboard,
            'data' => $dashboard->widgets->mapWithKeys(fn ($widget): array => [$widget->id => $widgets->dataFor($widget)]),
            'library' => $this->library($me, $available),
            'tables' => $available,
            'me' => $me,
            'openWidget' => $request->integer('widget') ?: null,
        ]);
    }

    public function update(SaveDashboardRequest $request, Dashboard $dashboard): RedirectResponse
    {
        $this->authorizeOrHide('update', $dashboard);

        $dashboard->update($request->safe()->only(['name', 'visibility']));

        return redirect()->route('dashboards.edit', $dashboard)->with('status', 'Dashboard saved.');
    }

    public function destroy(Dashboard $dashboard): RedirectResponse
    {
        $this->authorizeOrHide('delete', $dashboard);

        $dashboard->delete();

        return redirect()->route('dashboards.index')->with('status', "“{$dashboard->name}” deleted.");
    }

    /**
     * @param  Collection<int, Dashboard>  $dashboards
     */
    private function render(Dashboard $dashboard, User $me, Collection $dashboards): View
    {
        $dashboard->load('widgets');
        $widgets = new DashboardWidgets($me);

        return view('dashboards.show', [
            'dashboard' => $dashboard,
            'me' => $me,
            'shared' => $dashboards->filter(fn (Dashboard $d): bool => $d->isShared())->values(),
            'personal' => $dashboards->reject(fn (Dashboard $d): bool => $d->isShared())->values(),
            'data' => $dashboard->widgets->mapWithKeys(fn ($widget): array => [$widget->id => $widgets->dataFor($widget)]),
            'isDefault' => $me->default_dashboard_id === $dashboard->id,
        ]);
    }

    /**
     * What the person may add: the ticket widgets with access to tickets, and
     * a table widget for each table they may see.
     *
     * @param  array<string, TableSource>  $tables
     * @return list<array{type: string, source: ?string, label: string, description: string, icon: string}>
     */
    private function library(User $me, array $tables): array
    {
        $items = [];
        foreach ($tables as $key => $table) {
            $items[] = ['type' => DashboardWidgetType::Table->value, 'source' => $key, 'label' => "{$table->label()} table", 'description' => $table->description(), 'icon' => 'table'];
        }

        if ($me->can('tickets.view')) {
            foreach (DashboardWidgetType::cases() as $type) {
                if ($type->isAboutTickets()) {
                    $items[] = ['type' => $type->value, 'source' => null, 'label' => $type->label(), 'description' => $type->description(), 'icon' => 'chart'];
                }
            }
        }

        return $items;
    }

    /**
     * Dashboards the person can open: shared ones first, then their own.
     *
     * @return Collection<int, Dashboard>
     */
    private function visibleDashboards(User $me): Collection
    {
        return Dashboard::query()
            ->visibleTo($me)
            ->orderByRaw('case when visibility = ? then 0 else 1 end', [DashboardVisibility::Shared->value])
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /**
     * 404 when the person can't even see the dashboard, 403 when they can see
     * it but may not change it.
     */
    private function authorizeOrHide(string $ability, Dashboard $dashboard): void
    {
        abort_unless(Gate::allows('view', $dashboard), 404);
        Gate::authorize($ability, $dashboard);
    }
}
