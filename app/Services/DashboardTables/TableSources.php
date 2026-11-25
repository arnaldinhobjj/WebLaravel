<?php

namespace App\Services\DashboardTables;

use App\Models\User;

/**
 * Every table a dashboard table widget can show.
 */
class TableSources
{
    /** @var array<string, TableSource> */
    private array $sources = [];

    public function __construct(TicketsTable $tickets, PeopleTable $people, FilesTable $files, ActionRunsTable $actionRuns)
    {
        foreach ([$tickets, $people, $files, $actionRuns] as $source) {
            $this->sources[$source->key()] = $source;
        }
    }

    public function find(?string $key): ?TableSource
    {
        return $this->sources[(string) $key] ?? null;
    }

    /**
     * The tables $person may show.
     *
     * @return array<string, TableSource>
     */
    public function availableTo(User $person): array
    {
        return array_filter($this->sources, fn (TableSource $source): bool => $source->isAvailableTo($person));
    }
}
