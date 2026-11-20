<?php

namespace App\Console\Commands;

use Illuminate\Database\Console\Migrations\FreshCommand as BaseFreshCommand;

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
            fn () => $this->callSilent('db:wipe', array_filter([
                '--database' => $tickets,
                '--drop-views' => $this->option('drop-views'),
                '--drop-types' => $this->option('drop-types'),
                '--force' => true,
            ])) === 0,
        );
    }
}
