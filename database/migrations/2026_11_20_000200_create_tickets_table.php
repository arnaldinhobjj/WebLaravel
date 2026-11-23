<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tickets live on their own connection (Oracle by default), see config/fortvale.php.
     */
    public function getConnection(): ?string
    {
        return config('fortvale.connections.tickets');
    }

    public function up(): void
    {
        // A foreign key can't point at another database server, so links to
        // users are only enforced when tickets and users share a connection.
        $usersAreHere = $this->getConnection() === config('fortvale.connections.people');

        Schema::connection($this->getConnection())->create('tickets', function (Blueprint $table) use ($usersAreHere) {
            // Ticket numbers continue after the seeded sample tickets (#377xx).
            $table->id()->startingValue(38000);
            $table->string('subject');
            $table->text('description');
            $table->string('status')->default('open')->index();
            $table->string('priority')->default('normal')->index();
            $table->string('category')->nullable()->index();

            if ($usersAreHere) {
                $table->foreignId('requester_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            } else {
                $table->unsignedBigInteger('requester_id')->index();
                $table->unsignedBigInteger('assignee_id')->nullable()->index();
            }

            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        Schema::connection($this->getConnection())->create('ticket_activities', function (Blueprint $table) use ($usersAreHere) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();

            if ($usersAreHere) {
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            } else {
                $table->unsignedBigInteger('user_id')->nullable()->index();
            }

            $table->string('type'); // created, comment, status, priority, assignee, category
            $table->text('body')->nullable();
            // "from" and "to" are reserved words in Oracle.
            $table->string('from_label')->nullable();
            $table->string('to_label')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('ticket_activities');
        Schema::connection($this->getConnection())->dropIfExists('tickets');
    }
};
