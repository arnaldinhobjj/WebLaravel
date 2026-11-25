<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-widget choices made on the customize screen: an own title, its
     * width, and for table widgets which table, columns and filters (JSON in
     * a text column, so it works the same on MySQL, Oracle and SQLite).
     */
    public function up(): void
    {
        Schema::table('dashboard_widgets', function (Blueprint $table) {
            $table->string('title', 60)->nullable();
            $table->string('size', 10)->nullable(); // normal, wide; empty = the widget's usual width
            $table->text('settings')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('dashboard_widgets', function (Blueprint $table) {
            $table->dropColumn(['title', 'size', 'settings']);
        });
    }
};
