<?php

namespace App\Providers;

use App\Models\Ticket;
use App\Models\User;
use App\Services\Workspace;
use App\Support\CurrentUser;
use App\Support\PagePermissions;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Paginator::defaultView('partials.pagination');

        // Every "page.ability" in PagePermissions is checked against the person's tags.
        Gate::before(fn (User $user, string $ability): ?bool => PagePermissions::exists($ability) ? $user->hasPermission($ability) : null);
        Gate::define('workspace', fn (User $user): bool => $user->hasPermission('files.view') || $user->hasPermission('my_files.view'));
        Gate::define('actions-page', fn (User $user): bool => $user->hasPermission('actions.view') || $user->hasPermission('shared_actions.view'));
        Gate::define('tags-page', fn (User $user): bool => $user->hasPermission('tags.view') || $user->hasPermission('departments.view') || $user->hasPermission('people.view'));

        // Data every page's sidebar and title bar needs.
        View::composer('layouts.app', function ($view) {
            $me = CurrentUser::get();
            $badges = [
                'active_tickets' => Ticket::active()->visibleTo($me)->count(),
                'my_tickets' => Ticket::active()->where('assignee_id', $me->id)->count(),
            ];

            $view->with([
                'currentUser' => $me,
                'openMentionCount' => count((new Workspace(session()->driver()))->openMentions()),
                'navigation' => array_map(fn ($group) => $this->buildGroup($group, $badges), Workspace::NAVIGATION),
                'footerNavigation' => $this->buildGroup(Workspace::FOOTER_NAVIGATION, $badges),
            ]);
        });
    }

    /** Turns a navigation group from Workspace into links with URLs, active state and badges. */
    private function buildGroup(array $group, array $badges): array
    {
        $request = request();

        $group['items'] = array_values(array_filter(
            $group['items'],
            fn ($item): bool => ! isset($item['can']) || (bool) request()->user()?->can($item['can']),
        ));

        $items = array_map(function ($item) use ($request, $badges) {
            $item = is_string($item) ? ['label' => $item] : $item;
            $params = $item['params'] ?? [];
            $routeMatches = isset($item['route']) && $request->routeIs($item['route']);
            $paramsMatch = collect($params)->every(fn ($value, $key) => $request->query($key) === $value);

            return [
                'label' => $item['label'],
                'url' => isset($item['route']) ? route($item['route'], $params) : '#',
                'badge' => isset($item['badge']) ? ($badges[$item['badge']] ?: null) : null,
                // More specific links (with matching query parameters) win over plain ones.
                'score' => $routeMatches && $paramsMatch ? 1 + count($params) : 0,
            ];
        }, $group['items']);

        $best = max(array_column($items, 'score') ?: [0]);

        foreach ($items as &$item) {
            $item['active'] = $best > 0 && $item['score'] === $best;
        }

        $open = ($group['open'] ?? false) || collect($items)->contains('active', true);

        return ['label' => $group['label'], 'open' => $open, 'items' => $items];
    }
}
