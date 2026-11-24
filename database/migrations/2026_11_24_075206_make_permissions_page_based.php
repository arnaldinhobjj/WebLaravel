<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Permissions become "page.ability" (for example "tickets.edit"), and a
 * built-in Everyone tag holds what every person may do. The lists are
 * written out here so this migration doesn't change if the pages do later.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> */
    private const OLD_TO_NEW = [
        'people.manage' => [
            'tags.view', 'tags.create', 'tags.edit', 'tags.delete',
            'departments.view', 'departments.create', 'departments.edit', 'departments.delete',
            'people.view', 'people.create', 'people.edit',
        ],
        'actions.shared.edit' => ['shared_actions.view', 'shared_actions.edit'],
        'tickets.view_all' => ['tickets.view', 'tickets.view_all'],
    ];

    /** What everyone could do before permissions were page based. */
    private const EVERYONE = [
        'tickets.view', 'tickets.create', 'tickets.edit', 'tickets.reply', 'tickets.assign',
        'actions.view', 'actions.create', 'actions.edit', 'actions.delete', 'actions.run',
        'shared_actions.view', 'shared_actions.run',
    ];

    public function up(): void
    {
        Schema::table('tags', function (Blueprint $table) {
            $table->boolean('is_everyone')->default(false)->after('permissions');
        });

        foreach (DB::table('tags')->get(['id', 'permissions']) as $tag) {
            $new = [];
            foreach (json_decode($tag->permissions, true) ?: [] as $old) {
                array_push($new, ...(self::OLD_TO_NEW[$old] ?? [$old]));
            }
            DB::table('tags')->where('id', $tag->id)->update(['permissions' => json_encode(array_values(array_unique($new)))]);
        }

        DB::table('tags')->insert([
            'name' => 'Everyone',
            'description' => 'Everyone has this tag.',
            'permissions' => json_encode(self::EVERYONE),
            'is_everyone' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('tags')->where('is_everyone', true)->delete();

        foreach (DB::table('tags')->get(['id', 'permissions']) as $tag) {
            $new = json_decode($tag->permissions, true) ?: [];
            $old = array_keys(array_filter(self::OLD_TO_NEW, fn (array $mapped): bool => array_diff($mapped, $new) === []));
            DB::table('tags')->where('id', $tag->id)->update(['permissions' => json_encode($old)]);
        }

        Schema::table('tags', function (Blueprint $table) {
            $table->dropColumn('is_everyone');
        });
    }
};
