<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attachments can belong to things identified by text, such as a shared
 * action's file name, as well as to records with a number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->string('attachable_id', 100)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->unsignedBigInteger('attachable_id')->nullable()->change();
        });
    }
};
