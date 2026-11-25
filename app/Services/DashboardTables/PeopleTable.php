<?php

namespace App\Services\DashboardTables;

use App\Models\Department;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Everyone in Fort Vale, for people with the People permission. The tags
 * column and the tag filter also need the Tags permission.
 */
class PeopleTable extends TableSource
{
    public function key(): string
    {
        return 'people';
    }

    public function label(): string
    {
        return 'People';
    }

    public function description(): string
    {
        return 'Everyone in Fort Vale, by department or tag.';
    }

    public function isAvailableTo(User $person): bool
    {
        return $person->can('people.view');
    }

    public function columns(User $person): array
    {
        $columns = [
            'name' => 'Name',
            'email' => 'Email',
            'job_title' => 'Job title',
            'department' => 'Department',
        ];

        if ($person->can('tags.view')) {
            $columns['tags'] = 'Tags';
        }

        return $columns;
    }

    public function defaultColumns(): array
    {
        return ['name', 'job_title', 'department'];
    }

    public function filters(User $person): array
    {
        $filters = [
            'department' => [
                'label' => 'Department',
                'options' => ['' => 'Any department', 'none' => 'No department'] + $this->names(Department::orderBy('name')->get(['id', 'name'])),
                'default' => '',
            ],
        ];

        if ($person->can('tags.view')) {
            $filters['tag'] = [
                'label' => 'Tag',
                'options' => ['' => 'Any tag'] + $this->names(Tag::assignable()->orderBy('name')->get(['id', 'name'])),
                'default' => '',
            ];
        }

        return $filters;
    }

    protected function query(User $viewer, array $filters): Builder
    {
        $department = $filters['department'] ?? '';
        $tag = $filters['tag'] ?? '';

        return User::query()
            ->when($department === 'none', fn (Builder $query) => $query->whereNull('department_id'))
            ->when(ctype_digit($department), fn (Builder $query) => $query->where('department_id', (int) $department))
            // A tag reaches a person directly or through their department.
            ->when(ctype_digit($tag), fn (Builder $query) => $query->where(fn (Builder $tagged) => $tagged
                ->whereHas('tags', fn (Builder $tags) => $tags->whereKey((int) $tag))
                ->orWhereHas('department.tags', fn (Builder $tags) => $tags->whereKey((int) $tag))))
            ->with(['department', 'tags'])
            ->orderBy('name');
    }

    /**
     * @param  User  $row
     */
    protected function cell(Model $row, string $column): string
    {
        return match ($column) {
            'name' => $row->name,
            'email' => $row->email,
            'job_title' => $row->job_title ?: '—',
            'department' => $row->department?->name ?? '—',
            'tags' => $row->tags->pluck('name')->implode(', ') ?: '—',
            default => '',
        };
    }

    /**
     * @param  User  $row
     */
    protected function rowUrl(Model $row, User $viewer): ?string
    {
        return $viewer->can('people.edit') ? route('people.edit', $row) : null;
    }

    protected function listUrl(User $viewer, array $filters): ?string
    {
        return Gate::forUser($viewer)->allows('tags-page') ? route('tags.index') : null;
    }

    /**
     * @param  iterable<Model>  $models
     * @return array<string, string>
     */
    private function names(iterable $models): array
    {
        $names = [];
        foreach ($models as $model) {
            $names[(string) $model->getKey()] = $model->name;
        }

        return $names;
    }
}
