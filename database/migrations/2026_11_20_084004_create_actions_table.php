<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('description', 160)->nullable();
            $table->string('type', 10); // link, ticket
            $table->string('url', 2048)->nullable();
            $table->string('ticket_subject', 160)->nullable();
            $table->string('ticket_category', 20)->nullable();
            $table->string('ticket_priority', 10)->nullable();
            $table->text('ticket_description')->nullable();
            $table->boolean('show_on_home')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'show_on_home']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('actions');
    }
};
