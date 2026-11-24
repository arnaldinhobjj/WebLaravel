<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Skills each person switched off for their own AIs, and the permission to
 * switch skills off for everyone (given to whoever can manage people).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_settings', function (Blueprint $table) {
            $table->json('disabled_skills')->nullable()->after('system_prompt');
        });

        foreach (DB::table('tags')->get(['id', 'permissions']) as $tag) {
            $permissions = json_decode($tag->permissions, true) ?: [];
            if (in_array('people.edit', $permissions, true) && ! in_array('ai.manage_skills', $permissions, true)) {
                $permissions[] = 'ai.manage_skills';
                DB::table('tags')->where('id', $tag->id)->update(['permissions' => json_encode($permissions)]);
            }
        }
    }

    public function down(): void
    {
        foreach (DB::table('tags')->get(['id', 'permissions']) as $tag) {
            $permissions = array_values(array_diff(json_decode($tag->permissions, true) ?: [], ['ai.manage_skills']));
            DB::table('tags')->where('id', $tag->id)->update(['permissions' => json_encode($permissions)]);
        }

        Schema::table('ai_settings', function (Blueprint $table) {
            $table->dropColumn('disabled_skills');
        });
    }
};
