<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Yaml\Yaml;

/**
 * Personal actions become YAML documents (GitHub Actions style). Existing
 * "open a link" actions become a `page/open` step and ticket templates a
 * `ticket/open-form` step.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('actions', function (Blueprint $table) {
            $table->text('yaml')->nullable()->after('name');
            $table->json('triggers')->nullable()->after('show_on_home');
        });

        DB::table('actions')->orderBy('id')->each(function (object $action): void {
            $step = $action->type === 'ticket'
                ? ['name' => 'Open the new ticket form', 'uses' => 'ticket/open-form', 'with' => array_filter([
                    'subject' => $action->ticket_subject,
                    'category' => $action->ticket_category,
                    'priority' => $action->ticket_priority,
                    'description' => $action->ticket_description,
                ])]
                : ['name' => 'Open the page', 'uses' => 'page/open', 'with' => ['url' => $action->url]];

            $document = array_filter([
                'name' => $action->name,
                'description' => $action->description,
                'on' => ['run' => ['show_on_home' => (bool) $action->show_on_home]],
                'steps' => [$step],
            ], fn (mixed $value): bool => $value !== null);

            DB::table('actions')->where('id', $action->id)->update([
                // `on` is quoted by the dumper (a YAML 1.1 boolean); it reads fine unquoted.
                'yaml' => preg_replace("/^'on':/m", 'on:', Yaml::dump($document, 6, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_COMPACT_NESTED_MAPPING)),
                'triggers' => json_encode(['run']),
            ]);
        });

        Schema::table('actions', function (Blueprint $table) {
            $table->dropColumn(['description', 'type', 'url', 'ticket_subject', 'ticket_category', 'ticket_priority', 'ticket_description']);
        });
    }

    public function down(): void
    {
        Schema::table('actions', function (Blueprint $table) {
            $table->string('description', 160)->nullable();
            $table->string('type', 10)->default('link');
            $table->string('url', 2048)->nullable();
            $table->string('ticket_subject', 160)->nullable();
            $table->string('ticket_category', 20)->nullable();
            $table->string('ticket_priority', 10)->nullable();
            $table->text('ticket_description')->nullable();
            $table->dropColumn(['yaml', 'triggers']);
        });
    }
};
