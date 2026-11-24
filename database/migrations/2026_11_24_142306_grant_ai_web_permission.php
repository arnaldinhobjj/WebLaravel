<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AI: Search and read the web (web.search, web.read): everyone may by default,
 * like chatting; take it away from the Everyone tag to limit it.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('tags')->get(['id', 'permissions', 'is_everyone']) as $tag) {
            $permissions = json_decode($tag->permissions, true) ?: [];
            if (($tag->is_everyone || in_array('people.edit', $permissions, true)) && ! in_array('ai.web', $permissions, true)) {
                $permissions[] = 'ai.web';
                DB::table('tags')->where('id', $tag->id)->update(['permissions' => json_encode($permissions)]);
            }
        }
    }

    public function down(): void
    {
        foreach (DB::table('tags')->get(['id', 'permissions']) as $tag) {
            $permissions = array_values(array_diff(json_decode($tag->permissions, true) ?: [], ['ai.web']));
            DB::table('tags')->where('id', $tag->id)->update(['permissions' => json_encode($permissions)]);
        }
    }
};
