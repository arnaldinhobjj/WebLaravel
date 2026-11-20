<?php

namespace App\Providers;

use App\Models\Ticket;
use App\Models\User;
use App\Services\Workspace;
use App\Support\CurrentUser;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
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

        $this->wipeTicketsDatabaseOnFreshMigrations();

        // Data every page's sidebar and title bar needs.
        View::composer('layouts.app', function ($view) {
            $me = CurrentUser::get();
            $badges = [
                'active_tickets' => Ticket::active()->count(),
                'my_tickets' => Ticket::active()->where('assignee_id', $me->id)->count(),
            ];

            $view->with([
                'currentUser' => $me,
                'people' => User::orderBy('name')->get(),
                'openMentionCount' => count((new Workspace(session()->driver()))->openMentions()),
                'navigation' => array_map(fn ($group) => $this->buildGroup($group, $badges), Workspace::NAVIGATION),
                'footerNavigation' => $this->buildGroup(Workspace::FOOTER_NAVIGATION, $badges),
            ]);
        });
    }

    /**
     * `migrate:fresh` only wipes the default connection. Tickets can live in a
     * second database (Oracle by default), so wipe that one as well; otherwise
     * re-running the tickets migration fails on tables that already exist.
     */
    private function wipeTicketsDatabaseOnFreshMigrations(): void
    {
        Event::listen(function (CommandStarting $event): void {
            if ($event->command !== 'migrate:fresh' || $event->input->getParameterOption('--database')) {
                return;
            }

            $tickets = config('fortvale.connections.tickets');

            if ($tickets && $tickets !== config('fortvale.connections.people')) {
                Artisan::call('db:wipe', ['--database' => $tickets, '--force' => true]);
            }
        });
    }

    /** Turns a navigation group from Workspace into links with URLs, active state and badges. */
    private function buildGroup(array $group, array $badges): array
    {
        $request = request();

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
