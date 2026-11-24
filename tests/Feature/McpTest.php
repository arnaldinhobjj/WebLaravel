<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\AiToken;
use App\Models\AiTokenCall;
use App\Models\Department;
use App\Models\Folder;
use App\Models\StoredFile;
use App\Models\Tag;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Files\FileStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class McpTest extends TestCase
{
    use RefreshDatabase;

    private User $alex;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('files');
        config(['fortvale.actions.path' => storage_path('framework/testing/no-shared-actions')]);
        $this->alex = User::factory()->withPermissions('ai.connect')->create(['name' => 'Alex Morgan']);
        $this->token = AiToken::issue($this->alex, 'Test AI')['plain'];
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function rpc(string $method, array $params = [], ?string $token = null): TestResponse
    {
        return $this->postJson('/mcp/fortvale', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params], [
            'Authorization' => 'Bearer '.($token ?? $this->token),
            'Accept' => 'application/json, text/event-stream',
        ]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{text: string, error: bool}
     */
    private function useTool(string $tool, array $arguments = [], ?string $token = null): array
    {
        $result = $this->rpc('tools/call', ['name' => $tool, 'arguments' => $arguments], $token)->assertOk()->json('result');

        return ['text' => (string) ($result['content'][0]['text'] ?? ''), 'error' => (bool) ($result['isError'] ?? false)];
    }

    public function test_an_ai_connects_and_sees_the_tools_its_person_may_use(): void
    {
        $this->rpc('initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'test', 'version' => '1']])
            ->assertOk()->assertJsonPath('result.serverInfo.name', 'Fort Vale');

        $tools = collect($this->rpc('tools/list')->assertOk()->json('result.tools'))->keyBy('name');
        foreach (['tickets_search', 'tickets_get', 'tickets_create', 'tickets_reply', 'files_search', 'files_read', 'actions_list', 'actions_run', 'people_me', 'home_summary'] as $name) {
            $this->assertTrue($tools->has($name), "{$name} is offered");
        }
        $this->assertTrue($tools['tickets_search']['annotations']['readOnlyHint']);
        $this->assertTrue($tools['tickets_create']['annotations']['destructiveHint']);
        $this->assertSame(['ticket'], $tools['tickets_get']['inputSchema']['required']);

        // Recipes are MCP prompts.
        $prompts = collect($this->rpc('prompts/list')->assertOk()->json('result.prompts'))->pluck('name');
        $this->assertContains('recipes_summarise_ticket', $prompts);

        // With fewer permissions, fewer tools.
        Tag::everyone()->update(['permissions' => ['tickets.view']]);
        $tools = collect($this->rpc('tools/list')->json('result.tools'))->pluck('name')->all();
        $this->assertContains('tickets_search', $tools);
        $this->assertNotContains('tickets_create', $tools);
        $this->assertNotContains('files_search', $tools);
        // A tool that isn't offered can't be called either.
        $this->rpc('tools/call', ['name' => 'tickets_create', 'arguments' => ['subject' => 'x', 'description' => 'x']])->assertJsonStructure(['error' => ['message']]);
        $this->assertSame(0, Ticket::count());
    }

    public function test_wrong_or_revoked_tokens_and_people_without_permission_are_refused(): void
    {
        $this->rpc('tools/list', [], 'fv_wrong')->assertUnauthorized()->assertHeader('WWW-Authenticate');
        $this->postJson('/mcp/fortvale', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertUnauthorized();

        $this->alex->tags()->detach();
        $this->rpc('tools/list')->assertUnauthorized();

        AiToken::query()->delete();
        $this->rpc('tools/list')->assertUnauthorized();
    }

    public function test_tickets_are_limited_to_what_the_person_may_see(): void
    {
        $mine = Ticket::factory()->create(['subject' => 'Badge reader broken', 'requester_id' => $this->alex->id]);
        $mine->addComment('Still broken this morning.', $this->alex);
        $other = Ticket::factory()->create(['subject' => 'Badge reader in HR']);

        $found = json_decode($this->useTool('tickets_search', ['search' => 'badge'])['text'], true);
        $this->assertSame([$mine->id], array_column($found['tickets'], 'id'));

        $ticket = json_decode($this->useTool('tickets_get', ['ticket' => $mine->id])['text'], true);
        $this->assertSame('Still broken this morning.', $ticket['replies'][0]['text']);

        $hidden = $this->useTool('tickets_get', ['ticket' => $other->id]);
        $this->assertTrue($hidden['error']);
        $this->assertStringContainsString("wasn't found", $hidden['text']);
        $this->assertTrue($this->useTool('tickets_reply', ['ticket' => $other->id, 'text' => 'Hi'])['error']);
        $this->assertSame(0, $other->comments()->count());
    }

    public function test_creating_and_replying_act_as_the_person(): void
    {
        $created = json_decode($this->useTool('tickets_create', ['subject' => 'Printer jams', 'description' => 'Floor 2', 'category' => 'it', 'priority' => 'high'])['text'], true);
        $ticket = Ticket::findOrFail($created['id']);
        $this->assertSame([$this->alex->id, 'it', 'high'], [$ticket->requester_id, $ticket->category, $ticket->priority->value]);

        $this->useTool('tickets_reply', ['ticket' => $ticket->id, 'text' => 'Paper tray replaced.']);
        $this->assertSame($this->alex->id, $ticket->comments()->sole()->user_id);

        $invalid = $this->useTool('tickets_create', ['subject' => 'x', 'description' => 'x', 'category' => 'nonsense']);
        $this->assertTrue($invalid['error']);
    }

    public function test_files_follow_folder_access(): void
    {
        $open = Folder::factory()->create(['name' => 'Manuals']);
        $hr = Folder::factory()->create(['name' => 'HR']);
        $hr->access()->create(['department_id' => Department::factory()->create()->id, 'abilities' => ['view']]);
        $store = app(FileStore::class);
        $store->upload($this->alex, $open, UploadedFile::fake()->createWithContent('badge manual.txt', 'Hold the button for 10 seconds.'));
        $store->upload($this->alex, $hr, UploadedFile::fake()->createWithContent('badge salaries.txt', 'secret'));
        $store->upload($this->alex, $open, UploadedFile::fake()->createWithContent('photo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==')));

        $found = json_decode($this->useTool('files_search', ['search' => 'badge'])['text'], true);
        $this->assertSame(['badge manual.txt'], array_column($found['files'], 'name'));

        $manual = StoredFile::where('name', 'badge manual.txt')->sole();
        $this->assertSame('Hold the button for 10 seconds.', json_decode($this->useTool('files_read', ['file' => $manual->id])['text'], true)['text']);
        $this->assertTrue($this->useTool('files_read', ['file' => StoredFile::where('name', 'badge salaries.txt')->sole()->id])['error']);
        $this->assertStringContainsString("isn't a text file", $this->useTool('files_read', ['file' => StoredFile::where('name', 'photo.png')->sole()->id])['text']);
    }

    public function test_actions_can_be_listed_and_run_as_the_person(): void
    {
        $action = Action::factory()->for($this->alex)->yaml("name: Say hello\non:\n  run:\n    inputs:\n      who: { required: true }\nsteps:\n  - uses: message/show\n    with: { text: \"Hello \${{ inputs.who }} from \${{ actor.name }}\" }")->create();
        Action::factory()->yaml("name: Not mine\non: { run: }\nsteps:\n  - uses: message/show\n    with: { text: x }")->create();

        $listed = json_decode($this->useTool('actions_list')['text'], true)['actions'];
        $this->assertSame(['Say hello'], array_column($listed, 'name'));

        $this->assertStringContainsString('needs the input “who”', $this->useTool('actions_run', ['key' => $action->key()])['text']);
        $result = json_decode($this->useTool('actions_run', ['key' => $action->key(), 'inputs' => ['who' => 'Sam']])['text'], true);
        $this->assertSame('Hello Sam from Alex Morgan', $result['message']);

        $this->assertTrue($this->useTool('actions_run', ['key' => 'personal:999999'])['error']);
    }

    public function test_calls_are_logged_on_the_token_and_rate_limited(): void
    {
        $this->useTool('tickets_search');
        $this->useTool('tickets_get', ['ticket' => 999999]);

        $calls = AiTokenCall::orderBy('id')->get();
        $this->assertSame(['tickets_search', 'tickets_get'], $calls->pluck('tool')->all());
        $this->assertSame([true, false], $calls->pluck('ok')->all());
        $this->assertNotNull(AiToken::sole()->last_used_at);

        $this->actingAs($this->alex)->get(route('ai.index'))->assertSee('tickets_search')->assertSee("Ticket #999999 wasn't found.");

        for ($i = 0; $i < 58; $i++) {
            $this->rpc('tools/list');
        }
        $this->rpc('tools/list')->assertStatus(429);
    }
}
