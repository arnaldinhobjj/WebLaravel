<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * History of action runs, like a GitHub Actions run log: which action, what
 * started it, who, the outcome, and each step's result.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('action_runs', function (Blueprint $table) {
            $table->id();
            $table->string('action_key', 120)->index(); // shared:<file> or personal:<id>
            $table->string('action_name', 60);
            $table->string('source', 10); // shared, personal
            $table->foreignId('action_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // who started it
            $table->string('trigger', 10); // run, ticket, schedule
            $table->string('event', 20)->nullable(); // ticket event, e.g. created
            $table->unsignedBigInteger('ticket_id')->nullable(); // tickets may live in another database
            $table->string('status', 10); // success, failed
            $table->string('message', 300)->nullable();
            $table->json('steps');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'started_at']);
            $table->index(['owner_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('action_runs');
    }
};
