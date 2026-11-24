<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Changing and reassigning tickets is for tags that see every ticket
 * (the helpdesk, administrators), not for tags that only manage people.
 */
return new class extends Migration
{
    private const MOVED = ['tickets.edit', 'tickets.assign'];

    public function up(): void
    {
        foreach (DB::table('tags')->where('is_everyone', false)->get(['id', 'permissions']) as $tag) {
            $permissions = json_decode($tag->permissions, true) ?: [];
            if (! in_array('tickets.view_all', $permissions, true) && in_array('people.edit', $permissions, true)) {
                DB::table('tags')->where('id', $tag->id)->update(['permissions' => json_encode(array_values(array_diff($permissions, self::MOVED)))]);
            }
        }
    }

    public function down(): void
    {
        // Nothing to undo: the previous rule also gave these to people.edit tags.
    }
};
