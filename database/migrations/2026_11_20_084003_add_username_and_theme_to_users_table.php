<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 30)->nullable()->unique()->after('name');
            $table->string('theme', 10)->default('system')->after('job_title');
        });

        DB::table('users')->whereNull('username')->orderBy('id')->each(function (object $user) {
            $base = Str::slug($user->name, '.') ?: 'user';
            $username = $base;
            $suffix = 2;

            while (DB::table('users')->where('username', $username)->exists()) {
                $username = $base.$suffix++;
            }

            DB::table('users')->where('id', $user->id)->update(['username' => $username]);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn(['username', 'theme']);
        });
    }
};
