<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Databases created before Oracle support have ticket_activities.from/to;
 * "from" and "to" are reserved words in Oracle, so they become *_label.
 * Fresh installs already have the new names and this does nothing.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('fortvale.connections.tickets');
    }

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        if (! $schema->hasColumn('ticket_activities', 'from')) {
            return;
        }

        $schema->table('ticket_activities', function (Blueprint $table) {
            $table->renameColumn('from', 'from_label');
            $table->renameColumn('to', 'to_label');
        });
    }

    public function down(): void
    {
        // Nothing to undo: the original columns are not reintroduced.
    }
};
