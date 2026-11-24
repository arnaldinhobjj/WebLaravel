<?php

namespace Tests\Feature;

use App\Models\AiConnection;
use App\Models\AiConversation;
use App\Models\AiSetting;
use App\Models\AiToken;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AiTest extends TestCase
{
    use RefreshDatabase;

    private User $alex;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alex = User::factory()->withPermissions('ai.connect')->create(['name' => 'Alex Morgan']);
        $this->actingAs($this->alex);
    }

    public function test_the_page_is_in_the_sidebar_and_needs_the_chat_permission(): void
    {
        $this->get('/')->assertSee('href="'.route('ai.index').'"', false);
        $this->get(route('ai.index'))->assertOk()->assertSee('Your AIs')->assertSee('http://localhost:11434/v1')->assertSee('https://api.anthropic.com/v1');

        Tag::everyone()->update(['permissions' => []]);
        $this->actingAs(User::factory()->create())->get(route('ai.index'))->assertForbidden();
    }

    public function test_each_person_has_their_own_ais_with_keys_encrypted(): void
    {
        $this->post(route('ai.connections.store'), ['name' => 'Claude', 'provider' => 'anthropic', 'base_url' => 'https://api.anthropic.com/v1/', 'model' => 'claude-sonnet-5-5', 'api_key' => 'sk-ant-123'])
            ->assertRedirect();
        $this->post(route('ai.connections.store'), ['name' => 'Ollama', 'provider' => 'openai', 'base_url' => 'http://localhost:11434/v1', 'model' => 'qwen2.5:7b']);

        $claude = AiConnection::where('name', 'Claude')->sole();
        $this->assertSame(['anthropic', 'https://api.anthropic.com/v1', 'sk-ant-123'], [$claude->provider, $claude->base_url, $claude->api_key]);
        $this->assertStringNotContainsString('sk-ant-123', (string) DB::table('ai_connections')->where('id', $claude->id)->value('api_key'));

        // An empty key keeps the saved one; the key is only handed to its owner's browser.
        $this->put(route('ai.connections.update', $claude), ['name' => 'Claude', 'provider' => 'anthropic', 'base_url' => 'https://api.anthropic.com/v1', 'model' => 'claude-opus-5-5', 'api_key' => '']);
        $this->assertSame(['sk-ant-123', 'claude-opus-5-5'], [$claude->fresh()->api_key, $claude->fresh()->model]);
        $this->get(route('ai.index'))->assertSee('"api_key":"sk-ant-123"', false)->assertSee('Ollama · qwen2.5:7b');

        $sam = User::factory()->create();
        $this->actingAs($sam)->get(route('ai.index'))->assertDontSee('sk-ant-123')->assertDontSee('Ollama · qwen2.5:7b');
        $this->put(route('ai.connections.update', $claude), ['name' => 'x', 'provider' => 'openai', 'base_url' => 'http://x.test'])->assertNotFound();
        $this->delete(route('ai.connections.destroy', $claude))->assertNotFound();

        $this->actingAs($this->alex)->post(route('ai.connections.store'), ['name' => 'Bad', 'provider' => 'skynet', 'base_url' => 'not a url'])->assertSessionHasErrors(['provider', 'base_url']);
        $this->delete(route('ai.connections.destroy', $claude))->assertRedirect();
        $this->assertModelMissing($claude);

        $this->put(route('ai.settings'), ['system_prompt' => 'Be brief.'])->assertSessionHasNoErrors();
        $this->assertSame('Be brief.', AiSetting::find($this->alex->id)->system_prompt);
    }

    public function test_chats_are_saved_and_titled_from_the_first_message(): void
    {
        $connection = AiConnection::create(['user_id' => $this->alex->id, 'name' => 'Ollama', 'provider' => 'openai', 'base_url' => 'http://localhost:11434/v1', 'model' => 'llama3.2:1b']);
        $created = $this->postJson(route('ai.conversations.store'), ['connection_id' => $connection->id])->assertCreated()->json();

        $this->postJson(url("/ai/conversations/{$created['id']}/messages"), ['model' => 'llama3.2:1b', 'messages' => [
            ['role' => 'user', 'content' => "How do I reset\nthe badge reader?"],
            ['role' => 'assistant', 'content' => '', 'meta' => ['calls' => [['id' => 'call_1', 'name' => 'files_search', 'input' => ['search' => 'badge']]]]],
            ['role' => 'tool', 'content' => '{"ok":true,"summary":"1 file found."}', 'meta' => ['call_id' => 'call_1', 'name' => 'files_search', 'ok' => true, 'by' => 'ai']],
            ['role' => 'assistant', 'content' => 'Hold the button for 10 seconds.'],
        ]])->assertOk()->assertJson(['title' => 'How do I reset the badge reader?']);
        $this->postJson(url("/ai/conversations/{$created['id']}/messages"), ['messages' => [['role' => 'user', 'content' => 'Thanks'], ['role' => 'assistant', 'content' => 'You’re welcome.']]])
            ->assertJson(['title' => 'How do I reset the badge reader?']);

        $chat = AiConversation::sole();
        $this->assertSame($connection->id, $chat->connection_id);
        $this->assertSame(['user', 'assistant', 'tool', 'assistant', 'user', 'assistant'], $chat->messages->pluck('role')->all());
        $this->assertSame('files_search', $chat->messages[2]->meta['name']);
        $this->assertSame('llama3.2:1b', $chat->messages[3]->model);
        $this->postJson(url("/ai/conversations/{$created['id']}/messages"), ['messages' => [['role' => 'system', 'content' => 'x']]])->assertUnprocessable();

        $this->get(route('ai.index', ['c' => $chat->id]))
            ->assertSee('Ollama · llama3.2:1b')
            ->assertSee('"role":"assistant","content":"Hold the button for 10 seconds."', false)
            ->assertSee('"name":"files_search"', false);

        $this->patch(route('ai.conversations.rename', $chat), ['title' => 'Badge reader'])->assertSessionHasNoErrors();
        $this->assertSame('Badge reader', $chat->fresh()->title);
        $this->delete(route('ai.conversations.destroy', $chat))->assertRedirect(route('ai.index'));
        $this->assertModelMissing($chat);
    }

    public function test_nobody_else_can_see_or_change_your_chats(): void
    {
        $chat = AiConversation::create(['user_id' => $this->alex->id, 'title' => 'Private thoughts']);
        $admin = User::factory()->withPermissions('people.edit', 'tags.edit', 'ai.connect')->create();

        $this->actingAs($admin);
        $this->get(route('ai.index'))->assertDontSee('Private thoughts');
        $this->get(route('ai.index', ['c' => $chat->id]))->assertNotFound();
        $this->postJson(url("/ai/conversations/{$chat->id}/messages"), ['messages' => [['role' => 'user', 'content' => 'x']]])->assertNotFound();
        $this->postJson(route('ai.conversations.store'), ['connection_id' => AiConnection::create(['user_id' => $this->alex->id, 'name' => 'x', 'provider' => 'openai', 'base_url' => 'http://x.test'])->id])->assertNotFound();
        $this->patch(route('ai.conversations.rename', $chat), ['title' => 'Mine now'])->assertNotFound();
        $this->delete(route('ai.conversations.destroy', $chat))->assertNotFound();
        $this->assertSame('Private thoughts', $chat->fresh()->title);
    }

    public function test_tokens_are_shown_once_stored_as_a_hash_and_can_be_revoked(): void
    {
        $this->post(route('ai.tokens.store'), ['name' => 'LM Studio on my laptop'])->assertRedirect();
        $plain = session('new_ai_token');
        $this->assertStringStartsWith('fv_', $plain);

        $token = AiToken::sole();
        $this->assertSame(hash('sha256', $plain), $token->token_hash);
        $this->assertStringNotContainsString($plain, json_encode(DB::table('ai_tokens')->first()));

        $this->get(route('ai.index'))->assertSee($plain)->assertSee('won’t be shown again');
        $this->get(route('ai.index'))->assertSee('LM Studio on my laptop')->assertDontSee($plain);

        $this->actingAs(User::factory()->withPermissions('ai.connect')->create())->delete(route('ai.tokens.destroy', $token))->assertNotFound();
        $this->actingAs($this->alex)->delete(route('ai.tokens.destroy', $token))->assertRedirect();
        $this->assertModelMissing($token);
    }

    public function test_connecting_an_ai_needs_its_own_permission(): void
    {
        $sam = User::factory()->create();
        $this->actingAs($sam);

        $this->get(route('ai.index'))->assertOk()->assertDontSee('Let your AI use Fort Vale');
        $this->post(route('ai.tokens.store'), ['name' => 'x'])->assertForbidden();
    }
}
