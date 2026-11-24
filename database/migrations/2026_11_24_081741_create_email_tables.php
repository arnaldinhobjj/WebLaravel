<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Email: templates (four built in, for ticket emails), app-wide settings such
 * as the sender, each person's email choices, and a record of sent emails.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const EMAIL_PERMISSIONS = [
        'emails.view', 'emails.send',
        'email_templates.view', 'email_templates.create', 'email_templates.edit', 'email_templates.delete',
        'email_settings.view', 'email_settings.edit',
    ];

    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->nullable()->unique();
            $table->string('name', 60)->unique();
            $table->string('subject', 200);
            $table->text('body');
            $table->timestamps();
        });

        Schema::create('app_settings', function (Blueprint $table) {
            $table->string('key', 60)->primary();
            $table->json('value');
            $table->timestamps();
        });

        Schema::create('sent_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 10);
            $table->json('to');
            $table->string('subject', 200);
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'created_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->json('email_preferences')->nullable()->after('theme');
        });

        $now = now();
        DB::table('email_templates')->insert(array_map(fn (array $template): array => $template + ['created_at' => $now, 'updated_at' => $now], [
            [
                'key' => 'ticket.created',
                'name' => 'New ticket',
                'subject' => 'New ticket #${{ ticket.id }}: ${{ ticket.subject }}',
                'body' => "Hi \${{ recipient.name }},\n\n\${{ actor.name }} opened ticket #\${{ ticket.id }} (\${{ ticket.category }}, \${{ ticket.priority }} priority):\n\n\${{ ticket.subject }}\n\nOpen it: \${{ ticket.url }}",
            ],
            [
                'key' => 'ticket.assigned',
                'name' => 'Ticket assigned to you',
                'subject' => 'Assigned to you: #${{ ticket.id }} ${{ ticket.subject }}',
                'body' => "Hi \${{ recipient.name }},\n\n\${{ actor.name }} assigned ticket #\${{ ticket.id }} to you:\n\n\${{ ticket.subject }}\n\nOpen it: \${{ ticket.url }}",
            ],
            [
                'key' => 'ticket.replied',
                'name' => 'New reply on a ticket',
                'subject' => 'New reply on #${{ ticket.id }}: ${{ ticket.subject }}',
                'body' => "Hi \${{ recipient.name }},\n\n\${{ actor.name }} replied on ticket #\${{ ticket.id }}:\n\n\${{ reply.body }}\n\nOpen it: \${{ ticket.url }}",
            ],
            [
                'key' => 'ticket.status_changed',
                'name' => 'Ticket status changed',
                'subject' => '#${{ ticket.id }} is now ${{ ticket.status }}',
                'body' => "Hi \${{ recipient.name }},\n\n\${{ actor.name }} changed ticket #\${{ ticket.id }} (\${{ ticket.subject }}) to \${{ ticket.status }}.\n\nOpen it: \${{ ticket.url }}",
            ],
        ]));

        // Whoever can already manage people (the Administrator) also gets every email permission.
        foreach (DB::table('tags')->get(['id', 'permissions']) as $tag) {
            $permissions = json_decode($tag->permissions, true) ?: [];
            if (in_array('people.edit', $permissions, true)) {
                DB::table('tags')->where('id', $tag->id)->update([
                    'permissions' => json_encode(array_values(array_unique([...$permissions, ...self::EMAIL_PERMISSIONS]))),
                ]);
            }
        }
    }

    public function down(): void
    {
        foreach (DB::table('tags')->get(['id', 'permissions']) as $tag) {
            $permissions = array_values(array_diff(json_decode($tag->permissions, true) ?: [], self::EMAIL_PERMISSIONS));
            DB::table('tags')->where('id', $tag->id)->update(['permissions' => json_encode($permissions)]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('email_preferences');
        });
        Schema::dropIfExists('sent_emails');
        Schema::dropIfExists('app_settings');
        Schema::dropIfExists('email_templates');
    }
};
