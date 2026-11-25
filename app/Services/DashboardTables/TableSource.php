<?php

namespace App\Services\DashboardTables;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A table people can show in a dashboard table widget: which rows it has,
 * which columns and filters there are, and who may use which of them.
 *
 * Rows are always worked out for the person looking at the dashboard, never
 * for whoever set the widget up, so a shared dashboard can't show anyone rows
 * they couldn't open themselves.
 */
abstract class TableSource
{
    /** How many rows a table widget may show. */
    public const LIMITS = [5, 10, 15, 25];

    public const DEFAULT_LIMIT = 10;

    abstract public function key(): string;

    abstract public function label(): string;

    /** One line for the widget library. */
    abstract public function description(): string;

    /** Whether $person may see this table at all. */
    abstract public function isAvailableTo(User $person): bool;

    /**
     * The columns $person may show, as key => label.
     *
     * @return array<string, string>
     */
    abstract public function columns(User $person): array;

    /**
     * The columns a new widget starts with.
     *
     * @return list<string>
     */
    abstract public function defaultColumns(): array;

    /**
     * The filters $person may use, each with the values they may pick. The
     * value '' means "any" and is always allowed. `summary` can give an option
     * a clearer name for the line under the widget title; `in_summary` false
     * leaves the filter out of that line (an order, say).
     *
     * @return array<string, array{label: string, options: array<string, string>, default: string, summary?: array<string, string>, in_summary?: bool}>
     */
    abstract public function filters(User $person): array;

    /**
     * The rows $viewer may see, narrowed by the filters, in display order.
     *
     * @param  array<string, string>  $filters
     * @return Builder<covariant Model>
     */
    abstract protected function query(User $viewer, array $filters): Builder;

    /** The text of one cell. */
    abstract protected function cell(Model $row, string $column): string;

    /** Where clicking a row goes, if $viewer may open it. */
    abstract protected function rowUrl(Model $row, User $viewer): ?string;

    /**
     * The full list with the same filters, if there is a page for it.
     *
     * @param  array<string, string>  $filters
     */
    abstract protected function listUrl(User $viewer, array $filters): ?string;

    /**
     * Settings for a new widget showing this table.
     *
     * @return array{source: string, columns: list<string>, filters: array<string, string>, limit: int}
     */
    public function defaultSettings(User $editor): array
    {
        return [
            'source' => $this->key(),
            'columns' => array_values(array_intersect($this->defaultColumns(), array_keys($this->columns($editor)))),
            'filters' => array_map(fn (array $filter): string => $filter['default'], $this->filters($editor)),
            'limit' => self::DEFAULT_LIMIT,
        ];
    }

    /**
     * What a table widget shows $viewer.
     *
     * @param  array<string, mixed>  $settings
     * @return array{denied: string}|array{columns: array<string, string>, rows: list<array{cells: list<string>, url: ?string}>, url: ?string, summary: string}
     */
    public function table(User $viewer, array $settings): array
    {
        if (! $this->isAvailableTo($viewer)) {
            return ['denied' => "You don’t have access to {$this->label()}."];
        }

        $filters = $this->resolveFilters($viewer, (array) ($settings['filters'] ?? []));
        if ($filters === null) {
            return ['denied' => 'This table uses a filter that isn’t available to you.'];
        }

        $allowed = $this->columns($viewer);
        $columns = array_values(array_intersect((array) ($settings['columns'] ?? []), array_keys($allowed)));
        if ($columns === []) {
            $columns = array_values(array_intersect($this->defaultColumns(), array_keys($allowed)));
        }

        $limit = in_array((int) ($settings['limit'] ?? 0), self::LIMITS, true) ? (int) $settings['limit'] : self::DEFAULT_LIMIT;

        $rows = $this->query($viewer, $filters)->limit($limit)->get()
            ->map(fn (Model $row): array => [
                'cells' => array_map(fn (string $column): string => $this->cell($row, $column), $columns),
                'url' => $this->rowUrl($row, $viewer),
            ])
            ->values()
            ->all();

        return [
            'columns' => array_combine($columns, array_map(fn (string $column): string => $allowed[$column], $columns)),
            'rows' => $rows,
            'url' => $this->listUrl($viewer, $filters),
            'summary' => $this->summary($viewer, $filters),
        ];
    }

    /**
     * The saved filters with defaults filled in, or null when one of them uses
     * a value $viewer may not pick.
     *
     * @param  array<string, mixed>  $saved
     * @return array<string, string>|null
     */
    private function resolveFilters(User $viewer, array $saved): ?array
    {
        $definitions = $this->filters($viewer);
        $resolved = array_map(fn (array $filter): string => $filter['default'], $definitions);

        foreach ($saved as $key => $value) {
            $value = (string) $value;
            if ($value === '') {
                if (isset($resolved[$key])) {
                    $resolved[$key] = '';
                }

                continue;
            }
            if (! isset($definitions[$key]['options'][$value])) {
                return null;
            }
            $resolved[$key] = $value;
        }

        return $resolved;
    }

    /**
     * The chosen filters in words, e.g. "Active · Urgent · Assigned to me".
     *
     * @param  array<string, string>  $filters
     */
    private function summary(User $viewer, array $filters): string
    {
        $parts = [];
        foreach ($this->filters($viewer) as $key => $filter) {
            $value = $filters[$key] ?? '';
            if (($filter['in_summary'] ?? true) && $value !== '' && isset($filter['options'][$value])) {
                $parts[] = $filter['summary'][$value] ?? $filter['options'][$value];
            }
        }

        return implode(' · ', $parts);
    }
}
