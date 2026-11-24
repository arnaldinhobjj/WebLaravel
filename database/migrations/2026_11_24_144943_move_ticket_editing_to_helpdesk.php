<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Changing a ticket's status, priority and category (tickets.edit) and
 * reassigning it (tickets.assign) are no longer for everyone: they go to
 * tags that see every ticket (the helpdesk and administrators).
 * Everyone can still open tickets and reply.
 */
return new class extends Migration
{
    private const MOVED = ['tickets.edit', 'tickets.assign'];

    public function up(): void
    {
        foreach (DB::table('tags')->get(['id', 'permissions', 'is_everyone']) as $tag) {
            $permissions = json_decode($tag->permissions, true) ?: [];
            $permissions = match (true) {
                (bool) $tag->is_everyone => array_values(array_diff($permissions, self::MOVED)),
                in_array('tickets.view_all', $permissions, true) => array_values(array_unique([...$permissions, 'tickets.view', ...self::MOVED])),
                default => $permissions,
            };
            DB::table('tags')->where('id', $tag->id)->update(['permissions' => json_encode($permissions)]);
        }
    }

    public function down(): void
    {
        $everyone = DB::table('tags')->where('is_everyone', true)->first();
        if ($everyone !== null) {
            $permissions = array_values(array_unique([...(json_decode($everyone->permissions, true) ?: []), ...self::MOVED]));
            DB::table('tags')->where('id', $everyone->id)->update(['permissions' => json_encode($permissions)]);
        }
    }
};
