<?php

namespace Tests\Feature;

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
        $this->get(route('ai.index'))->assertOk()->assertSee('Connect your AI')->assertSee('http://localhost:11434/v1');

        Tag::everyone()->update(['permissions' => []]);
        $this->actingAs(User::factory()->create())->get(route('ai.index'))->assertForbidden();
    }

    public function test_connection_settings_are_saved_per_person_with_the_key_encrypted(): void
    {
        $this->put(route('ai.settings'), ['base_url' => 'http://localhost:1234/v1/', 'model' => 'qwen2.5', 'api_key' => 'sk-local-123', 'system_prompt' => 'Be brief.'])
            ->assertSessionHasNoErrors();

        $settings = AiSetting::find($this->alex->id);
        $this->assertSame(['http://localhost:1234/v1', 'qwen2.5', 'sk-local-123'], [$settings->base_url, $settings->model, $settings->api_key]);
        $this->assertStringNotContainsString('sk-local-123', (string) DB::table('ai_settings')->value('api_key'));

        // An empty key keeps the saved one; the page hands it only to its owner's browser.
        $this->put(route('ai.settings'), ['base_url' => 'http://localhost:1234/v1', 'model' => 'qwen2.5', 'api_key' => '']);
        $this->assertSame('sk-local-123', AiSetting::find($this->alex->id)->api_key);
        $this->get(route('ai.index'))->assertSee('data-api-key="sk-local-123"', false)->assertSee('data-model="qwen2.5"', false);

        $sam = User::factory()->create();
        $this->actingAs($sam)->get(route('ai.index'))->assertDontSee('sk-local-123')->assertSee('data-model=""', false);

        $this->actingAs($this->alex)->put(route('ai.settings'), ['base_url' => 'not a url'])->assertSessionHasErrors('base_url');
    }

    public function test_chats_are_saved_and_titled_from_the_first_message(): void
    {
        $created = $this->postJson(route('ai.conversations.store'))->assertCreated()->json();

        $this->postJson(url("/ai/conversations/{$created['id']}/messages"), ['user' => "How do I reset\nthe badge reader?", 'assistant' => 'Hold the button for 10 seconds.', 'model' => 'llama3.2:1b'])
            ->assertOk()->assertJson(['title' => 'How do I reset the badge reader?']);
        $this->postJson(url("/ai/conversations/{$created['id']}/messages"), ['user' => 'Thanks', 'assistant' => 'You’re welcome.'])
            ->assertJson(['title' => 'How do I reset the badge reader?']);

        $chat = AiConversation::sole();
        $this->assertSame(['user', 'assistant', 'user', 'assistant'], $chat->messages->pluck('role')->all());
        $this->assertSame('llama3.2:1b', $chat->messages[1]->model);

        $this->get(route('ai.index', ['c' => $chat->id]))
            ->assertSeeInOrder(['How do I reset the badge reader?', 'Hold the button for 10 seconds.', 'Thanks', 'You’re welcome.'])
            ->assertSee('"role":"assistant","content":"Hold the button for 10 seconds."', false);

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
        $this->postJson(url("/ai/conversations/{$chat->id}/messages"), ['user' => 'x', 'assistant' => 'y'])->assertNotFound();
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
