<?php

namespace App\Console\Commands;

use Illuminate\Database\Connection;
use Illuminate\Database\Console\Migrations\FreshCommand as BaseFreshCommand;
use Illuminate\Support\Facades\DB;
use Yajra\Oci8\Oci8Connection;
use Yajra\Oci8\Schema\OraclePreferences;

/**
 * `migrate:fresh` that also wipes the tickets database.
 *
 * Laravel's command only wipes the connection it migrates (the people
 * database). Tickets can live in a second database (Oracle by default), whose
 * tables would otherwise survive and break the re-run of the tickets migration.
 */
class FreshCommand extends BaseFreshCommand
{
    public function handle()
    {
        // Same safeguards as Laravel's command, checked before anything is wiped.
        if ($this->isProhibited() || ! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $this->wipeTicketsDatabase();

        // Already confirmed above; don't ask a second time in production.
        $this->input->setOption('force', true);

        return parent::handle();
    }

    private function wipeTicketsDatabase(): void
    {
        $people = config('fortvale.connections.people');
        $tickets = config('fortvale.connections.tickets');
        $refreshing = $this->input->getOption('database') ?? $people;

        if ($refreshing !== $people || $tickets === $people) {
            return;
        }

        $this->components->task(
            "Dropping all tables on the tickets connection [{$tickets}]",
            fn () => $this->dropEverything(DB::connection($tickets)),
        );
    }

    private function dropEverything(Connection $connection): bool
    {
        $schema = $connection->getSchemaBuilder();

        if ($this->option('drop-views')) {
            $schema->dropAllViews();
        }

        $this->dropAllTables($connection);

        if ($this->option('drop-types')) {
            $schema->dropAllTypes();
        }

        return true;
    }

    /**
     * yajra/laravel-oci8 first drops Oracle Text preferences, which fails with
     * ORA-00942 on databases without Oracle Text (such as Oracle Free "slim").
     * Skip that step when Oracle Text isn't installed.
     */
    private function dropAllTables(Connection $connection): void
    {
        if ($connection instanceof Oci8Connection && ! (new OraclePreferences($connection))->checkIfCtxsysIsEnabled()) {
            $connection->statement($connection->getSchemaGrammar()->compileDropAllTables());

            return;
        }

        $connection->getSchemaBuilder()->dropAllTables();
    }
}
