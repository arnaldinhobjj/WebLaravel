<?php

namespace App\Services\DashboardTables;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Tickets, with the same filters as the ticket list. Only tickets the viewer
 * may open (Ticket::visibleTo); picking a specific assignee needs the
 * "See everyone's tickets" permission.
 */
class TicketsTable extends TableSource
{
    public function key(): string
    {
        return 'tickets';
    }

    public function label(): string
    {
        return 'Tickets';
    }

    public function description(): string
    {
        return 'Tickets you can open, filtered like the ticket list.';
    }

    public function isAvailableTo(User $person): bool
    {
        return $person->can('tickets.view');
    }

    public function columns(User $person): array
    {
        return [
            'number' => 'Number',
            'subject' => 'Subject',
            'status' => 'Status',
            'priority' => 'Priority',
            'category' => 'Category',
            'requester' => 'Requester',
            'assignee' => 'Assignee',
            'opened' => 'Opened',
            'updated' => 'Updated',
        ];
    }

    public function defaultColumns(): array
    {
        return ['number', 'subject', 'status', 'priority', 'assignee'];
    }

    public function filters(User $person): array
    {
        $statuses = ['active' => 'Active (open, in progress, waiting)'];
        foreach (TicketStatus::cases() as $status) {
            $statuses[$status->value] = $status->label();
        }

        $priorities = [];
        foreach (array_reverse(TicketPriority::cases()) as $priority) {
            $priorities[$priority->value] = $priority->label();
        }

        $assignees = ['me' => 'Me', 'unassigned' => 'Nobody (unassigned)'];
        $assigneeSummary = ['me' => 'Assigned to me', 'unassigned' => 'Unassigned'];
        if ($person->can('tickets.view_all')) {
            foreach (User::orderBy('name')->get(['id', 'name']) as $user) {
                $assignees[(string) $user->id] = $user->name;
                $assigneeSummary[(string) $user->id] = "Assigned to {$user->name}";
            }
        }

        return [
            'status' => ['label' => 'Status', 'options' => ['' => 'Any status'] + $statuses, 'default' => 'active', 'summary' => ['active' => 'Active']],
            'priority' => ['label' => 'Priority', 'options' => ['' => 'Any priority'] + $priorities, 'default' => ''],
            'category' => ['label' => 'Category', 'options' => ['' => 'Any category'] + Ticket::CATEGORIES, 'default' => ''],
            'assignee' => ['label' => 'Assigned to', 'options' => ['' => 'Anyone'] + $assignees, 'default' => '', 'summary' => $assigneeSummary],
            'requester' => ['label' => 'Opened by', 'options' => ['' => 'Anyone', 'me' => 'Me'], 'default' => '', 'summary' => ['me' => 'Opened by me']],
            'sort' => ['label' => 'Order', 'options' => ['updated' => 'Recently updated', 'priority' => 'Highest priority first', 'newest' => 'Newest first', 'oldest' => 'Oldest first'], 'default' => 'updated', 'in_summary' => false],
        ];
    }

    protected function query(User $viewer, array $filters): Builder
    {
        return Ticket::query()
            ->visibleTo($viewer)
            ->filteredBy($filters, $viewer)
            ->inStatusTab($filters['status'] ?? 'active')
            ->sortedBy($filters['sort'] ?? 'updated')
            ->with(['requester', 'assignee']);
    }

    /**
     * @param  Ticket  $row
     */
    protected function cell(Model $row, string $column): string
    {
        return match ($column) {
            'number' => "#{$row->id}",
            'subject' => $row->subject,
            'status' => $row->status->label(),
            'priority' => $row->priority->label(),
            'category' => $row->categoryLabel(),
            'requester' => $row->requester?->name ?? '—',
            'assignee' => $row->assignee?->name ?? 'Unassigned',
            'opened' => $row->created_at->diffForHumans(),
            'updated' => $row->updated_at->diffForHumans(),
            default => '',
        };
    }

    /**
     * @param  Ticket  $row
     */
    protected function rowUrl(Model $row, User $viewer): ?string
    {
        return route('tickets.show', $row);
    }

    protected function listUrl(User $viewer, array $filters): ?string
    {
        $status = $filters['status'] ?? 'active';
        $query = [
            'status' => match ($status) {
                'active' => null,
                '' => 'all',
                default => $status,
            },
            'sort' => ($filters['sort'] ?? 'updated') === 'updated' ? null : $filters['sort'],
        ];
        foreach (['priority', 'category', 'assignee', 'requester'] as $key) {
            $query[$key] = ($filters[$key] ?? '') === '' ? null : $filters[$key];
        }

        return route('tickets.index', array_filter($query, fn (?string $value): bool => $value !== null));
    }
}
