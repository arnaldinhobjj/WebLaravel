<?php

namespace Tests\Feature;

use App\Ai\Skills\SkillRegistry;
use App\Mail\FortValeEmail;
use App\Models\Action;
use App\Models\AiSetting;
use App\Models\AiToken;
use App\Models\AppSetting;
use App\Models\Department;
use App\Models\Folder;
use App\Models\StoredFile;
use App\Models\Tag;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Files\FileStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SkillsTest extends TestCase
{
    use RefreshDatabase;

    private User $alex;

    private SkillRegistry $skills;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('files');
        Mail::fake();
        config(['fortvale.actions.path' => storage_path('framework/testing/no-shared-actions')]);
        $this->alex = User::factory()->withPermissions(
            'tickets.view_all', 'departments.view', 'emails.view', 'emails.send', 'email_templates.view', 'files.delete', 'ai.connect',
        )->create(['name' => 'Alex Morgan', 'username' => 'alex.morgan', 'email' => 'alex@fortvale.test']);
        $this->skills = app(SkillRegistry::class);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, summary: string, data: array<string, mixed>}
     */
    private function use(string $name, array $input = [], ?User $as = null): array
    {
        return $this->skills->run($as ?? $this->alex, $name, $input)->toArray();
    }

    // The registry

    public function test_every_skill_is_described_for_any_ai(): void
    {
        $names = [];
        foreach ($this->skills->abilities() + $this->skills->recipes() as $skill) {
            $this->assertMatchesRegularExpression('/^[a-z]+\.[a-z_]+$/', $skill->name());
            $this->assertMatchesRegularExpression('/^[a-zA-Z0-9_-]{1,64}$/', $skill->toolName(), 'OpenAI, Anthropic and MCP accept this tool name');
            $this->assertGreaterThan(20, strlen($skill->description()));
            $names[] = $skill->toolName();
        }
        $this->assertSame($names, array_unique($names));
        $this->assertGreaterThanOrEqual(30, count($this->skills->abilities()));
        $this->assertGreaterThanOrEqual(10, count($this->skills->recipes()));

        foreach ($this->skills->abilities() as $skill) {
            $schema = json_decode(json_encode($skill->inputSchema()), true);
            $this->assertSame('object', $schema['type']);
            $this->assertEmpty(array_diff($skill->required(), array_keys($schema['properties'] ?? [])), "{$skill->name()}: required inputs are declared");
        }
        foreach ($this->skills->recipes() as $recipe) {
            foreach ($recipe->uses() as $used) {
                $this->assertArrayHasKey($used, $this->skills->abilities(), "{$recipe->name()} uses a real skill");
            }
        }
    }

    public function test_skills_follow_permissions_and_switches(): void
    {
        $nobody = User::factory()->create();
        Tag::everyone()->update(['permissions' => ['tickets.view']]);
        $this->assertSame(['tickets.search', 'tickets.get', 'tickets.my_queue', 'tickets.activity', 'tickets.stale', 'people.me', 'people.find', 'home.summary'], array_keys($this->skills->availableAbilities($nobody)));
        $this->assertFalse($this->use('tickets.create', ['subject' => 'x', 'description' => 'x'], $nobody)['ok']);

        AiSetting::for($this->alex)->fill(['disabled_skills' => ['email.send']])->save();
        $this->assertArrayNotHasKey('email.send', $this->skills->availableAbilities($this->alex));
        $this->assertArrayHasKey('email.send', $this->skills->availableAbilities(User::factory()->withPermissions('emails.send')->create()));

        AppSetting::put('ai.disabled_skills', ['tickets.stale']);
        $this->assertArrayNotHasKey('tickets.stale', $this->skills->availableAbilities($nobody));
        $this->assertStringContainsString('switched off', $this->use('tickets.stale', [], $nobody)['summary']);
    }

    public function test_input_is_checked_against_the_schema(): void
    {
        $this->assertStringContainsString('required', $this->use('tickets.get')['summary']);
        $this->assertFalse($this->use('tickets.search', ['status' => 'exploded'])['ok']);
        $this->assertFalse($this->use('tickets.search', ['limit' => 500])['ok']);
        $this->assertFalse($this->use('nonsense.skill')['ok']);
        $this->assertTrue($this->use('tickets_search')['ok'], 'tool names work too');
    }

    // Tickets

    public function test_ticket_skills_act_as_the_person_and_respect_visibility(): void
    {
        $sam = User::factory()->create(['username' => 'sam.okafor', 'name' => 'Sam Okafor']);
        $created = $this->use('tickets.create', ['subject' => 'Camera 9 offline', 'description' => 'No picture', 'category' => 'cctv', 'priority' => 'urgent']);
        $id = $created['data']['id'];

        $this->assertTrue($this->use('tickets.reply', ['ticket' => $id, 'text' => 'On it.'])['ok']);
        $this->assertTrue($this->use('tickets.update', ['ticket' => $id, 'status' => 'in_progress'])['ok']);
        $this->assertSame('Sam Okafor', $this->use('tickets.assign', ['ticket' => $id, 'to' => 'sam.okafor'])['data']['assignee']);
        $this->assertFalse($this->use('tickets.assign', ['ticket' => $id, 'to' => 'ghost'])['ok']);

        $activity = $this->use('tickets.activity', ['ticket' => $id])['data']['events'];
        $this->assertStringContainsString('replied: On it.', implode('|', array_column($activity, 'what')));
        $this->assertSame('On it.', $this->use('tickets.get', ['ticket' => $id])['data']['replies'][0]['text']);
        $this->assertCount(1, $this->use('tickets.my_queue')['data']['opened_by_me']['in_progress']);
        $this->assertSame(1, $this->use('home.summary')['data']['urgent']);

        $hidden = Ticket::factory()->create();
        Ticket::query()->whereKey($hidden->id)->update(['updated_at' => now()->subDays(10)]);
        $this->assertSame([$hidden->id], array_column($this->use('tickets.stale', ['days' => 7])['data']['tickets'], 'id'));
        $this->assertFalse($this->use('tickets.get', ['ticket' => $hidden->id], $sam)['ok']);
        $this->assertSame([], $this->use('tickets.search', ['search' => 'Camera'], User::factory()->create())['data']['tickets']);
    }

    // People

    public function test_people_and_department_skills(): void
    {
        $it = Department::factory()->create(['name' => 'IT']);
        User::factory()->for($it)->create(['name' => 'Sam Okafor', 'username' => 'sam.okafor', 'job_title' => 'IT support']);

        $this->assertSame('alex.morgan', $this->use('people.me')['data']['username']);
        $this->assertSame(['sam.okafor'], array_column($this->use('people.find', ['search' => 'support'])['data']['people'], 'username'));
        $this->assertContains('IT', array_column($this->use('departments.list')['data']['departments'], 'name'));
        $this->assertSame('Sam Okafor', $this->use('departments.members', ['department' => 'it'])['data']['people'][0]['name']);
        $this->assertFalse($this->use('departments.list', [], User::factory()->create())['ok']);
    }

    // Files

    public function test_file_skills_follow_folder_access(): void
    {
        $quality = Folder::factory()->create(['name' => 'Quality']);
        $hr = Folder::factory()->create(['name' => 'HR']);
        $hr->access()->create(['department_id' => Department::factory()->create()->id, 'abilities' => ['view']]);
        app(FileStore::class)->upload($this->alex, $hr, UploadedFile::fake()->createWithContent('salaries.txt', 'secret'));

        $this->assertSame('Library/Quality/Audits', $this->use('files.create_folder', ['in' => 'Library/Quality', 'name' => 'Audits'])['data']['path']);
        $saved = $this->use('files.save_text', ['folder' => 'Library/Quality/Audits', 'name' => 'notes.md', 'content' => 'v1']);
        $this->use('files.save_text', ['folder' => 'Library/Quality/Audits', 'name' => 'notes.md', 'content' => 'v2']);
        $id = $saved['data']['id'];

        $this->assertSame('v2', $this->use('files.read', ['file' => $id])['data']['text']);
        $this->assertCount(2, $this->use('files.details', ['file' => $id])['data']['versions']);
        $this->assertSame(['Audits'], array_column($this->use('files.list_folder', ['path' => 'Library/Quality'])['data']['folders'], 'name'));
        $this->assertSame(['notes.md'], array_column($this->use('files.search', ['search' => 'notes'])['data']['files'], 'name'));
        $this->assertContains('notes.md', array_column($this->use('files.recent')['data']['files'], 'name'));

        $this->assertSame([], $this->use('files.search', ['search' => 'salaries'])['data']['files']);
        $this->assertFalse($this->use('files.list_folder', ['path' => 'Library/HR'])['ok']);
        $this->assertFalse($this->use('files.read', ['file' => StoredFile::where('name', 'salaries.txt')->sole()->id])['ok']);
        $this->assertFalse($this->use('files.save_text', ['folder' => 'Library/HR', 'name' => 'x.txt', 'content' => 'x'])['ok']);

        $this->assertTrue($this->use('files.save_text', ['folder' => 'My files', 'name' => 'todo.txt', 'content' => 'x'])['ok']);
        $this->assertTrue($this->use('files.trash', ['file' => $id])['ok']);
        $this->assertSoftDeleted(StoredFile::withTrashed()->find($id));
    }

    // Email

    public function test_email_skills(): void
    {
        User::factory()->create(['username' => 'sam.okafor', 'email' => 'sam@fortvale.test']);

        $this->assertContains('New ticket', array_column($this->use('email.templates')['data']['templates'], 'name'));
        $sent = $this->use('email.send', ['to' => ['sam.okafor', 'x@example.com'], 'subject' => 'Drill', 'text' => 'Hi ${{ recipient.name }}']);
        $this->assertTrue($sent['ok']);
        Mail::assertSent(FortValeEmail::class, 2);
        $this->assertSame('Drill', $this->use('email.sent')['data']['emails'][0]['subject']);

        $this->assertFalse($this->use('email.send', ['to' => ['not an address'], 'subject' => 'x', 'text' => 'x'])['ok']);
        $this->assertFalse($this->use('email.send', ['to' => ['x@example.com']])['ok']);
        $this->assertFalse($this->use('email.send', ['to' => ['x@example.com'], 'subject' => 'x', 'text' => 'x'], User::factory()->create())['ok']);
    }

    // Actions

    public function test_action_skills(): void
    {
        $action = Action::factory()->for($this->alex)->yaml("name: Say hi\non:\n  run:\n    inputs:\n      who: { required: true }\nsteps:\n  - uses: message/show\n    with: { text: \"Hi \${{ inputs.who }}\" }")->create();

        $this->assertSame([$action->key()], array_column($this->use('actions.list')['data']['actions'], 'key'));
        $this->assertStringContainsString('message/show', $this->use('actions.explain', ['key' => $action->key()])['data']['yaml']);
        $this->assertFalse($this->use('actions.run', ['key' => $action->key()])['ok']);
        $this->assertSame('Hi Sam', $this->use('actions.run', ['key' => $action->key(), 'inputs' => ['who' => 'Sam']])['summary']);
        $this->assertSame('Say hi', $this->use('actions.recent_runs')['data']['runs'][0]['action']);

        $this->assertTrue($this->use('actions.validate', ['yaml' => "name: X\non: { run: }\nsteps:\n  - uses: message/show\n    with: { text: x }"])['data']['valid']);
        $invalid = $this->use('actions.validate', ['yaml' => "name: X\non: { run: }\nsteps:\n  - uses: shell/run"])['data'];
        $this->assertFalse($invalid['valid']);
        $this->assertStringContainsString('steps[0].uses', $invalid['errors'][0]);

        $this->assertFalse($this->use('actions.explain', ['key' => $action->key()], User::factory()->create())['ok']);
    }

    // Recipes

    public function test_recipes_fill_in_their_arguments_and_need_their_skills(): void
    {
        $result = $this->skills->recipe($this->alex, 'recipes.summarise_ticket', ['ticket' => '37941']);
        $this->assertTrue($result->ok);
        $this->assertStringContainsString('ticket #37941', $result->data['instructions']);
        $this->assertStringContainsString('tickets_get', $result->data['instructions'], 'recipes name skills the way AIs see them');
        $this->assertStringNotContainsString('tickets.get', $result->data['instructions']);

        $this->assertFalse($this->skills->recipe($this->alex, 'recipes_summarise_ticket', [])->ok, 'the ticket is required');
        $this->assertStringContainsString('friendly tone', $this->skills->recipe($this->alex, 'recipes.draft_reply', ['ticket' => '1'])->data['instructions']);

        Tag::everyone()->update(['permissions' => []]);
        $this->assertArrayNotHasKey('recipes.summarise_ticket', $this->skills->availableRecipes(User::factory()->create()));
    }

    // HTTP adapter: OpenAI-style tools, OpenAPI and plain runs

    public function test_the_http_adapter_offers_openai_tools_openapi_and_runs(): void
    {
        $token = AiToken::issue($this->alex, 'ChatGPT')['plain'];
        $auth = ['Authorization' => "Bearer {$token}"];

        $tools = $this->getJson('/api/ai/skills', $auth)->assertOk()->json();
        $search = collect($tools['tools'])->firstWhere('function.name', 'tickets_search');
        $this->assertSame('function', $search['type']);
        $this->assertSame('object', $search['function']['parameters']['type']);
        $this->assertContains('recipes_my_day', array_column($tools['recipes'], 'name'));

        $spec = $this->getJson('/api/ai/openapi.json', $auth)->assertOk()->json();
        $this->assertSame('3.1.0', $spec['openapi']);
        $this->assertSame('tickets_create', $spec['paths']['/api/ai/skills/tickets_create']['post']['operationId']);
        $this->assertTrue($spec['paths']['/api/ai/skills/tickets_create']['post']['x-changes-things']);

        $this->postJson('/api/ai/skills/tickets_create', ['subject' => 'From ChatGPT', 'description' => 'x'], $auth)->assertOk()->assertJsonPath('ok', true);
        $this->assertSame($this->alex->id, Ticket::where('subject', 'From ChatGPT')->sole()->requester_id);
        $this->postJson('/api/ai/skills/tickets_get', [], $auth)->assertStatus(422)->assertJsonPath('ok', false);
        $this->getJson('/api/ai/recipes/recipes_summarise_ticket?ticket=5', $auth)->assertOk()->assertJsonPath('title', 'Summarise a ticket');

        AppSetting::put('ai.disabled_skills', ['tickets.create']);
        $this->postJson('/api/ai/skills/tickets_create', ['subject' => 'x', 'description' => 'x'], $auth)->assertStatus(422);
        $this->assertNotContains('tickets_create', array_column(array_column($this->getJson('/api/ai/skills', $auth)->json('tools'), 'function'), 'name'));

        $this->getJson('/api/ai/skills')->assertUnauthorized();
        $this->getJson('/api/ai/skills', ['Authorization' => 'Bearer fv_nope'])->assertUnauthorized();
    }

    // The Skills page

    public function test_the_skills_page_lists_everything_and_switches_skills_off(): void
    {
        $this->actingAs($this->alex);
        $this->get(route('ai.index'))->assertSee(route('ai.skills'));
        $page = $this->get(route('ai.skills'))->assertOk();
        foreach ($this->skills->abilities() + $this->skills->recipes() as $skill) {
            $page->assertSee('id="'.$skill->toolName().'"', false);
        }
        $page->assertDontSee('Switch off for everyone');

        $this->put(route('ai.skills.toggle', 'email_send'), ['for' => 'me', 'on' => 0])->assertRedirect();
        $this->assertSame(['email.send'], $this->skills->switchedOffBy($this->alex));
        $this->get(route('ai.skills'))->assertSee('Off for your AIs');
        $this->put(route('ai.skills.toggle', 'email_send'), ['for' => 'me', 'on' => 1]);
        $this->assertSame([], $this->skills->switchedOffBy($this->alex));

        $this->put(route('ai.skills.toggle', 'email_send'), ['for' => 'everyone', 'on' => 0])->assertForbidden();
        $admin = User::factory()->withPermissions('ai.manage_skills')->create();
        $this->actingAs($admin)->put(route('ai.skills.toggle', 'email_send'), ['for' => 'everyone', 'on' => 0])->assertRedirect();
        $this->assertSame(['email.send'], $this->skills->switchedOffForEveryone());
        $this->get(route('ai.skills'))->assertSee('Off for everyone')->assertSee('Switch on for everyone');
    }

    public function test_try_it_and_the_chat_run_skills_as_you(): void
    {
        $this->actingAs($this->alex);

        $this->post(route('ai.skills.run', 'tickets_create'), ['input' => ['subject' => 'Tried it', 'description' => 'From the form', 'priority' => 'high']])
            ->assertRedirect()->assertSessionHas('skill_result.ok', true);
        $this->assertSame([$this->alex->id, 'high'], [Ticket::where('subject', 'Tried it')->sole()->requester_id, Ticket::where('subject', 'Tried it')->sole()->priority->value]);
        $this->get(route('ai.skills'))->assertSee('tickets.create:')->assertSee('opened.');

        $this->postJson(route('ai.skills.run', 'people_me'))->assertOk()->assertJsonPath('data.username', 'alex.morgan');
        $this->postJson(route('ai.skills.run', 'email_send'), ['to' => ['nobody']])->assertStatus(422)->assertJsonPath('ok', false);
        $this->post(route('ai.recipes.show', 'recipes_summarise_ticket'), ['arguments' => ['ticket' => '42']])->assertSessionHas('skill_result.data.instructions');
        $this->postJson(route('ai.recipes.show', 'recipes_my_day'))->assertOk()->assertJsonStructure(['data' => ['instructions']]);

        Tag::everyone()->update(['permissions' => []]);
        $this->actingAs(User::factory()->create())->get(route('ai.skills'))->assertForbidden();
    }
}
