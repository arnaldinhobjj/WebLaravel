<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Dashboards: everyone may keep their own (view, create, edit, delete), like
 * personal actions. Sharing dashboards with everyone goes to the tags that
 * manage people, like other team-wide settings.
 */
return new class extends Migration
{
    private const OWN = ['dashboards.view', 'dashboards.create', 'dashboards.edit', 'dashboards.delete'];

    public function up(): void
    {
        foreach (DB::table('tags')->get(['id', 'permissions', 'is_everyone']) as $tag) {
            $permissions = json_decode($tag->permissions, true) ?: [];
            $isAdmin = in_array('people.edit', $permissions, true);

            $grant = match (true) {
                $isAdmin => [...self::OWN, 'dashboards.share'],
                (bool) $tag->is_everyone => self::OWN,
                default => [],
            };

            $merged = array_values(array_unique([...$permissions, ...$grant]));
            if ($merged !== $permissions) {
                DB::table('tags')->where('id', $tag->id)->update(['permissions' => json_encode($merged)]);
            }
        }
    }

    public function down(): void
    {
        foreach (DB::table('tags')->get(['id', 'permissions']) as $tag) {
            $permissions = array_values(array_diff(json_decode($tag->permissions, true) ?: [], [...self::OWN, 'dashboards.share']));
            DB::table('tags')->where('id', $tag->id)->update(['permissions' => json_encode($permissions)]);
        }
    }
};
