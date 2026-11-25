<?php

namespace App\Services;

use App\Enums\DashboardWidgetType;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\WidgetScope;
use App\Models\DashboardWidget;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Works out what each dashboard widget shows for the person looking at it.
 *
 * Every ticket query starts from Ticket::visibleTo(), so a shared dashboard
 * never shows anyone tickets they couldn't open themselves. "Just mine"
 * widgets count the viewer's own tickets, whoever made the dashboard.
 */
class DashboardWidgets
{
    private const LIST_LIMIT = 8;

    private const TREND_DAYS = 14;

    public function __construct(private User $viewer) {}

    /**
     * @return array<string, mixed>
     */
    public function dataFor(DashboardWidget $widget): array
    {
        if (! $this->viewer->can('tickets.view')) {
            return ['denied' => true];
        }

        return match ($widget->type) {
            DashboardWidgetType::TicketSummary => $this->summary($widget->scope),
            DashboardWidgetType::TicketQueue => $this->queue($widget->scope),
            DashboardWidgetType::TicketsByStatus => $this->byStatus($widget->scope),
            DashboardWidgetType::TicketsByPriority => $this->byPriority($widget->scope),
            DashboardWidgetType::TicketsByCategory => $this->byCategory($widget->scope),
            DashboardWidgetType::ResolvedTrend => $this->resolvedTrend($widget->scope),
            DashboardWidgetType::RecentActivity => $this->recentActivity($widget->scope),
        };
    }

    /**
     * Tickets this widget may count: what the viewer can see, narrowed to
     * their assigned tickets for "Just mine".
     *
     * @return Builder<Ticket>
     */
    private function tickets(WidgetScope $scope): Builder
    {
        return Ticket::query()
            ->visibleTo($this->viewer)
            ->when($scope === WidgetScope::Mine, fn (Builder $q) => $q->where('assignee_id', $this->viewer->id));
    }

    /**
     * Link to the ticket list with the same filter as the widget.
     *
     * @param  array<string, string>  $filters
     */
    private function ticketsUrl(WidgetScope $scope, array $filters = []): string
    {
        $scoped = $scope === WidgetScope::Mine ? ['assignee' => 'me'] : [];

        return route('tickets.index', [...$filters, ...$scoped]);
    }

    /**
     * @return array{tiles: list<array{label: string, value: int, url: string, tone?: string}>}
     */
    private function summary(WidgetScope $scope): array
    {
        $active = $this->tickets($scope)->active();
        $resolvedSince = now()->subDays(7);

        $tiles = [
            ['label' => $scope === WidgetScope::Mine ? 'Assigned to me' : 'Open', 'value' => (clone $active)->count(), 'url' => $this->ticketsUrl($scope)],
            $scope === WidgetScope::Mine
                ? ['label' => 'Waiting on others', 'value' => $this->tickets($scope)->where('status', TicketStatus::Waiting)->count(), 'url' => $this->ticketsUrl($scope, ['status' => 'waiting'])]
                : ['label' => 'Unassigned', 'value' => (clone $active)->whereNull('assignee_id')->count(), 'url' => route('tickets.index', ['assignee' => 'unassigned'])],
            ['label' => 'Urgent', 'value' => (clone $active)->where('priority', TicketPriority::Urgent)->count(), 'url' => $this->ticketsUrl($scope, ['priority' => 'urgent']), 'tone' => 'urgent'],
            ['label' => 'Resolved in 7 days', 'value' => $this->tickets($scope)->where('resolved_at', '>=', $resolvedSince)->count(), 'url' => $this->ticketsUrl($scope, ['status' => 'all'])],
        ];

        return ['tiles' => $tiles];
    }

    /**
     * @return array{tickets: Collection<int, Ticket>, url: string}
     */
    private function queue(WidgetScope $scope): array
    {
        $tickets = $this->tickets($scope)
            ->active()
            ->with('assignee')
            ->orderByRaw("case priority when 'urgent' then 4 when 'high' then 3 when 'normal' then 2 else 1 end desc")
            ->oldest('created_at')
            ->limit(self::LIST_LIMIT)
            ->get();

        return ['tickets' => $tickets, 'url' => $this->ticketsUrl($scope, ['sort' => 'priority'])];
    }

    /**
     * @return array{bars: list<array{label: string, value: int, url: string}>, total: int}
     */
    private function byStatus(WidgetScope $scope): array
    {
        $counts = $this->tickets($scope)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return $this->bars(array_map(fn (TicketStatus $status): array => [
            'label' => $status->label(),
            'value' => (int) ($counts[$status->value] ?? 0),
            'url' => $this->ticketsUrl($scope, ['status' => $status->value]),
        ], TicketStatus::cases()));
    }

    /**
     * @return array{bars: list<array{label: string, value: int, url: string}>, total: int}
     */
    private function byPriority(WidgetScope $scope): array
    {
        $counts = $this->tickets($scope)->active()->selectRaw('priority, count(*) as total')->groupBy('priority')->pluck('total', 'priority');

        return $this->bars(array_map(fn (TicketPriority $priority): array => [
            'label' => $priority->label(),
            'value' => (int) ($counts[$priority->value] ?? 0),
            'url' => $this->ticketsUrl($scope, ['priority' => $priority->value]),
        ], array_reverse(TicketPriority::cases())));
    }

    /**
     * @return array{bars: list<array{label: string, value: int, url: string}>, total: int}
     */
    private function byCategory(WidgetScope $scope): array
    {
        $counts = $this->tickets($scope)->active()->selectRaw('category, count(*) as total')->groupBy('category')->pluck('total', 'category');

        $bars = [];
        foreach (Ticket::CATEGORIES as $value => $label) {
            if ((int) ($counts[$value] ?? 0) > 0) {
                $bars[] = ['label' => $label, 'value' => (int) $counts[$value], 'url' => $this->ticketsUrl($scope, ['category' => $value])];
            }
        }
        usort($bars, fn (array $a, array $b): int => $b['value'] <=> $a['value']);

        return $this->bars($bars);
    }

    /**
     * Resolved or closed tickets per day. Grouped in PHP so it works the same
     * on MySQL, Oracle and SQLite.
     *
     * @return array{days: list<array{date: Carbon, label: string, value: int}>, total: int, max: int}
     */
    private function resolvedTrend(WidgetScope $scope): array
    {
        $start = now()->startOfDay()->subDays(self::TREND_DAYS - 1);
        $perDay = $this->tickets($scope)
            ->where('resolved_at', '>=', $start)
            ->pluck('resolved_at')
            ->countBy(fn ($resolvedAt): string => Carbon::parse($resolvedAt)->toDateString());

        $days = [];
        for ($day = $start->copy(); $day->lte(now()); $day->addDay()) {
            $days[] = ['date' => $day->copy(), 'label' => $day->format('j M'), 'value' => (int) ($perDay[$day->toDateString()] ?? 0)];
        }

        $values = array_column($days, 'value');

        return ['days' => $days, 'total' => array_sum($values), 'max' => max($values ?: [0])];
    }

    /**
     * Latest replies and changes. "Just mine" covers tickets the viewer opened
     * or is assigned to.
     *
     * @return array{activities: Collection<int, TicketActivity>}
     */
    private function recentActivity(WidgetScope $scope): array
    {
        $tickets = Ticket::query()
            ->visibleTo($this->viewer)
            ->when($scope === WidgetScope::Mine, fn (Builder $q) => $q->where(fn (Builder $mine) => $mine
                ->where('requester_id', $this->viewer->id)
                ->orWhere('assignee_id', $this->viewer->id)))
            ->select('id');

        $activities = TicketActivity::query()
            ->whereIn('ticket_id', $tickets)
            ->with(['ticket', 'user'])
            ->latest()
            ->latest('id')
            ->limit(self::LIST_LIMIT)
            ->get();

        return ['activities' => $activities];
    }

    /**
     * @param  list<array{label: string, value: int, url: string}>  $bars
     * @return array{bars: list<array{label: string, value: int, url: string}>, total: int, max: int}
     */
    private function bars(array $bars): array
    {
        $values = array_column($bars, 'value');

        return ['bars' => $bars, 'total' => array_sum($values), 'max' => max($values ?: [0])];
    }
}
