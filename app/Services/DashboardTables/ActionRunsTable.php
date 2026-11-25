<?php

namespace App\Services\DashboardTables;

use App\Models\ActionRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Action runs the viewer may see: ones they started and runs of their own
 * actions (ActionRun::visibleTo), for people with the My actions permission.
 */
class ActionRunsTable extends TableSource
{
    public function key(): string
    {
        return 'action_runs';
    }

    public function label(): string
    {
        return 'Action runs';
    }

    public function description(): string
    {
        return 'Recent runs of actions: yours, and ones you started.';
    }

    public function isAvailableTo(User $person): bool
    {
        return $person->can('actions.view');
    }

    public function columns(User $person): array
    {
        return [
            'action' => 'Action',
            'result' => 'Result',
            'trigger' => 'Started by',
            'message' => 'Message',
            'started' => 'When',
        ];
    }

    public function defaultColumns(): array
    {
        return ['action', 'result', 'started'];
    }

    public function filters(User $person): array
    {
        return [
            'result' => [
                'label' => 'Result',
                'options' => ['' => 'Any result', ActionRun::SUCCESS => 'Succeeded', ActionRun::FAILED => 'Failed'],
                'default' => '',
            ],
            'started_by' => [
                'label' => 'Started',
                'options' => ['' => 'By me or for my actions', 'me' => 'By me'],
                'default' => '',
                'summary' => ['me' => 'Started by me'],
            ],
        ];
    }

    protected function query(User $viewer, array $filters): Builder
    {
        $result = $filters['result'] ?? '';

        return ActionRun::query()
            ->visibleTo($viewer)
            ->when(in_array($result, [ActionRun::SUCCESS, ActionRun::FAILED], true), fn (Builder $query) => $query->where('status', $result))
            ->when(($filters['started_by'] ?? '') === 'me', fn (Builder $query) => $query->where('user_id', $viewer->id))
            ->with('user')
            ->latest('started_at')
            ->latest('id');
    }

    /**
     * @param  ActionRun  $row
     */
    protected function cell(Model $row, string $column): string
    {
        return match ($column) {
            'action' => (string) $row->action_name,
            'result' => $row->succeeded() ? 'Succeeded' : ($row->status === ActionRun::FAILED ? 'Failed' : ucfirst((string) $row->status)),
            'trigger' => $row->user?->name ?? ucfirst((string) ($row->trigger ?: 'automatic')),
            'message' => (string) ($row->message ?: '—'),
            'started' => $row->started_at?->diffForHumans() ?? '—',
            default => '',
        };
    }

    protected function rowUrl(Model $row, User $viewer): ?string
    {
        return null;
    }

    protected function listUrl(User $viewer, array $filters): ?string
    {
        return $viewer->username ? route('actions.index', $viewer) : null;
    }
}
