<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Materials: everyone may look them up. Uploading blueprints, photos and 3D
 * models goes to the tags that manage people, like other team-wide settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('tags')->get(['id', 'permissions', 'is_everyone']) as $tag) {
            $permissions = json_decode($tag->permissions, true) ?: [];
            $isAdmin = in_array('people.edit', $permissions, true);

            $grant = match (true) {
                $isAdmin => ['materials.view', 'materials.edit'],
                (bool) $tag->is_everyone => ['materials.view'],
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
            $permissions = array_values(array_diff(json_decode($tag->permissions, true) ?: [], ['materials.view', 'materials.edit']));
            DB::table('tags')->where('id', $tag->id)->update(['permissions' => json_encode($permissions)]);
        }
    }
};
