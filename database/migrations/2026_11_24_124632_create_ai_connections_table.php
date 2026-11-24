<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Several AI connections per person (a local AI, OpenAI, Claude, Gemini…),
 * chosen per chat. The single connection kept in ai_settings becomes the
 * person's first connection. Chat messages can also be skill calls (`tool`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('provider', 20)->default('openai');
            $table->string('base_url', 300);
            $table->string('model', 200)->nullable();
            $table->text('api_key')->nullable();
            $table->timestamps();
        });

        foreach (DB::table('ai_settings')->whereNotNull('base_url')->get() as $setting) {
            DB::table('ai_connections')->insert([
                'user_id' => $setting->user_id,
                'name' => 'My AI',
                'provider' => 'openai',
                'base_url' => $setting->base_url,
                'model' => $setting->model,
                'api_key' => $setting->api_key,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('ai_settings', function (Blueprint $table) {
            $table->dropColumn(['base_url', 'model', 'api_key']);
        });

        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->foreignId('connection_id')->nullable()->after('user_id')->constrained('ai_connections')->nullOnDelete();
        });

        Schema::table('ai_messages', function (Blueprint $table) {
            $table->json('meta')->nullable()->after('model');
        });
    }

    public function down(): void
    {
        Schema::table('ai_messages', function (Blueprint $table) {
            $table->dropColumn('meta');
        });
        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('connection_id');
        });
        Schema::table('ai_settings', function (Blueprint $table) {
            $table->string('base_url', 300)->nullable();
            $table->string('model', 200)->nullable();
            $table->text('api_key')->nullable();
        });

        foreach (DB::table('ai_connections')->orderBy('id')->get()->unique('user_id') as $connection) {
            DB::table('ai_settings')->updateOrInsert(['user_id' => $connection->user_id], [
                'base_url' => $connection->base_url, 'model' => $connection->model, 'api_key' => $connection->api_key,
            ]);
        }

        Schema::dropIfExists('ai_connections');
    }
};
