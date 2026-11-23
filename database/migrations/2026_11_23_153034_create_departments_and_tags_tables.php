<?php

use App\Enums\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Departments and tags. A department grants its tags to everyone in it; people
 * can also be given tags directly. The first person becomes Administrator so an
 * existing install isn't locked out of the Tags page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->timestamps();
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name', 40)->unique();
            $table->string('description', 160)->nullable();
            $table->json('permissions');
            $table->timestamps();
        });

        Schema::create('department_tag', function (Blueprint $table) {
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['department_id', 'tag_id']);
        });

        Schema::create('tag_user', function (Blueprint $table) {
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['tag_id', 'user_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->after('job_title')->constrained()->nullOnDelete();
        });

        $firstUser = DB::table('users')->orderBy('id')->value('id');
        if ($firstUser !== null) {
            $tag = DB::table('tags')->insertGetId([
                'name' => 'Administrator',
                'description' => 'Every permission.',
                'permissions' => json_encode(array_column(Permission::cases(), 'value')),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('tag_user')->insert(['tag_id' => $tag, 'user_id' => $firstUser]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
        });
        Schema::dropIfExists('tag_user');
        Schema::dropIfExists('department_tag');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('departments');
    }
};
