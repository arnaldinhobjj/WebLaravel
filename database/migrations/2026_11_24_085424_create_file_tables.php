<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The file system: folders (the library has one root, each person a private
 * root), files with their versions (stored in S3), and per-folder access for
 * tags and departments. Deleted folders and files stay in the trash
 * (`deleted_at`) for 30 days.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('folders')->cascadeOnDelete();
            $table->string('name', 120);
            $table->foreignId('owner_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['parent_id', 'name']);
        });

        Schema::create('folder_access', function (Blueprint $table) {
            $table->id();
            $table->foreignId('folder_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->cascadeOnDelete();
            $table->json('abilities');
        });

        Schema::create('files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('folder_id')->nullable()->constrained()->cascadeOnDelete();
            $table->nullableMorphs('attachable');
            $table->string('name', 200);
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('mime', 120)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['folder_id', 'name']);
        });

        Schema::create('file_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('file_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('path', 200);
            $table->unsignedBigInteger('size');
            $table->string('mime', 120);
            $table->char('sha256', 64);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['file_id', 'number']);
        });

        DB::table('folders')->insert(['name' => 'Library', 'created_at' => now(), 'updated_at' => now()]);

        // Everyone gets their own files and can browse and add to the library;
        // whoever can manage people also manages the library and file settings.
        foreach (DB::table('tags')->get(['id', 'permissions', 'is_everyone']) as $tag) {
            $permissions = json_decode($tag->permissions, true) ?: [];
            $add = match (true) {
                (bool) $tag->is_everyone => ['my_files.view', 'my_files.create', 'my_files.edit', 'my_files.delete', 'files.view', 'files.create', 'files.edit'],
                in_array('people.edit', $permissions, true) => ['files.view', 'files.create', 'files.edit', 'files.delete', 'files.manage_access', 'files.empty_trash', 'file_settings.view', 'file_settings.edit'],
                default => [],
            };
            if ($add !== []) {
                DB::table('tags')->where('id', $tag->id)->update(['permissions' => json_encode(array_values(array_unique([...$permissions, ...$add])))]);
            }
        }
    }

    public function down(): void
    {
        foreach (DB::table('tags')->get(['id', 'permissions']) as $tag) {
            $permissions = array_values(array_filter(
                json_decode($tag->permissions, true) ?: [],
                fn (string $permission): bool => ! preg_match('/^(files|my_files|file_settings)\./', $permission),
            ));
            DB::table('tags')->where('id', $tag->id)->update(['permissions' => json_encode($permissions)]);
        }

        Schema::dropIfExists('file_versions');
        Schema::dropIfExists('files');
        Schema::dropIfExists('folder_access');
        Schema::dropIfExists('folders');
    }
};
