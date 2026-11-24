<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AI, per person: the connection to their own local AI, their saved chats,
 * and access tokens that let their AI use Fort Vale (MCP), with a log of
 * what each token did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_settings', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('base_url', 300)->nullable();
            $table->string('model', 200)->nullable();
            $table->text('api_key')->nullable();
            $table->text('system_prompt')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 120)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'updated_at']);
        });

        Schema::create('ai_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('ai_conversations')->cascadeOnDelete();
            $table->string('role', 10);
            $table->longText('content');
            $table->string('model', 200)->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('ai_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->char('token_hash', 64)->unique();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_token_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('token_id')->constrained('ai_tokens')->cascadeOnDelete();
            $table->string('tool', 60);
            $table->boolean('ok');
            $table->string('summary', 300)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['token_id', 'created_at']);
        });

        // Everyone may chat; whoever can manage people may also connect their AI.
        foreach (DB::table('tags')->get(['id', 'permissions', 'is_everyone']) as $tag) {
            $permissions = json_decode($tag->permissions, true) ?: [];
            $add = match (true) {
                (bool) $tag->is_everyone => ['ai.chat'],
                in_array('people.edit', $permissions, true) => ['ai.chat', 'ai.connect'],
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
            $permissions = array_values(array_diff(json_decode($tag->permissions, true) ?: [], ['ai.chat', 'ai.connect']));
            DB::table('tags')->where('id', $tag->id)->update(['permissions' => json_encode($permissions)]);
        }

        Schema::dropIfExists('ai_token_calls');
        Schema::dropIfExists('ai_tokens');
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_conversations');
        Schema::dropIfExists('ai_settings');
    }
};
