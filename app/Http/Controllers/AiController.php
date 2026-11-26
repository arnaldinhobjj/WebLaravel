<?php

namespace App\Http\Controllers;

use App\Ai\Skills\Recipe;
use App\Ai\Skills\Skill;
use App\Ai\Skills\SkillRegistry;
use App\Models\AiConnection;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiSetting;
use App\Models\AiTask;
use App\Models\AiToken;
use App\Support\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The /ai page: chat with your own local AI (your browser talks to it
 * directly), your saved chats, your connection settings, and tokens that let
 * your AI use Fort Vale. Everything here belongs to one person only.
 */
class AiController extends Controller
{
    public function index(Request $request, SkillRegistry $skills): View
    {
        $me = CurrentUser::get();
        $conversation = $request->integer('c') ? $this->own(AiConversation::findOrFail($request->integer('c'))) : null;
        $connections = AiConnection::where('user_id', $me->id)->orderBy('id')->get();

        return view('ai.index', [
            'settings' => AiSetting::for($me),
            'connections' => $connections,
            // Handed only to the owner's browser, which talks to the AI directly.
            'browserConnections' => $connections->map(fn (AiConnection $c): array => [
                'id' => $c->id, 'name' => $c->name, 'provider' => $c->provider, 'base_url' => $c->base_url, 'model' => $c->model, 'api_key' => $c->api_key,
            ])->values(),
            'connection' => $connections->firstWhere('id', $conversation?->connection_id ?? $request->integer('connection')) ?? $connections->first(),
            'conversations' => AiConversation::where('user_id', $me->id)->latest('updated_at')->latest('id')->limit(100)->get(),
            'conversation' => $conversation,
            // An action's AI task writing in this chat (see ai/report), if any.
            'task' => $conversation ? AiTask::where('conversation_id', $conversation->id)->latest('id')->first() : null,
            'lastMessageId' => $conversation?->messages->max('id') ?? 0,
            'history' => $conversation?->messages->map(fn (AiMessage $message) => ['role' => $message->role, 'content' => $message->content, 'meta' => $message->meta])->values() ?? collect(),
            'skills' => [
                'abilities' => array_values(array_map(fn (Skill $skill): array => [
                    'name' => $skill->toolName(),
                    'title' => $skill->title(),
                    'description' => $skill->description(),
                    'schema' => $skill->inputSchema(),
                    'changes' => $skill->changesThings(),
                ], $skills->availableAbilities($me))),
                'recipes' => array_values(array_map(fn (Recipe $recipe): array => [
                    'name' => $recipe->toolName(),
                    'title' => $recipe->title(),
                    'description' => $recipe->description(),
                    'arguments' => $recipe->arguments(),
                ], $skills->availableRecipes($me))),
            ],
            'tokens' => Gate::allows('ai.connect') ? AiToken::where('user_id', $me->id)->with(['calls' => fn ($q) => $q->limit(5)])->latest('id')->get() : collect(),
            'newToken' => session('new_ai_token'),
        ]);
    }

    /**
     * Instructions (system prompt) for all your AIs.
     */
    public function saveSettings(Request $request): RedirectResponse
    {
        $data = $request->validate(['system_prompt' => ['nullable', 'string', 'max:4000']]);
        AiSetting::for(CurrentUser::get())->fill(['system_prompt' => $data['system_prompt'] ?? null])->save();

        return back()->with('status', 'Instructions saved.');
    }

    public function storeConnection(Request $request): RedirectResponse
    {
        $connection = AiConnection::create(['user_id' => CurrentUser::get()->id] + $this->validatedConnection($request));

        return redirect()->route('ai.index', ['connection' => $connection->id])->with('status', "“{$connection->name}” added.");
    }

    public function updateConnection(Request $request, AiConnection $connection): RedirectResponse
    {
        $this->ownConnection($connection)->update($this->validatedConnection($request, $connection));

        return back()->with('status', "“{$connection->name}” saved.");
    }

    public function destroyConnection(AiConnection $connection): RedirectResponse
    {
        $this->ownConnection($connection)->delete();

        return redirect()->route('ai.index')->with('status', "“{$connection->name}” removed. Chats with it are kept.");
    }

    public function storeConversation(Request $request): JsonResponse
    {
        $me = CurrentUser::get();
        $connectionId = $request->integer('connection_id') ?: null;
        abort_if($connectionId !== null && ! AiConnection::where('user_id', $me->id)->whereKey($connectionId)->exists(), 404);

        $conversation = AiConversation::create(['user_id' => $me->id, 'connection_id' => $connectionId]);

        return response()->json(['id' => $conversation->id, 'url' => route('ai.index', ['c' => $conversation->id])], 201);
    }

    /**
     * Saves one turn of a chat: your message, the AI's answer, and any skills
     * used along the way (in order).
     */
    public function storeMessages(Request $request, AiConversation $conversation): JsonResponse
    {
        $this->own($conversation);
        $data = $request->validate([
            'messages' => ['required', 'array', 'min:1', 'max:40'],
            'messages.*.role' => ['required', Rule::in(['user', 'assistant', 'tool'])],
            'messages.*.content' => ['present', 'nullable', 'string', 'max:200000'],
            'messages.*.meta' => ['nullable', 'array'],
            'model' => ['nullable', 'string', 'max:200'],
        ]);

        foreach ($data['messages'] as $message) {
            $conversation->messages()->create([
                'role' => $message['role'],
                'content' => (string) ($message['content'] ?? ''),
                'model' => $message['role'] === 'assistant' ? ($data['model'] ?? null) : null,
                'meta' => $message['meta'] ?? null,
            ]);
        }

        $firstQuestion = collect($data['messages'])->firstWhere('role', 'user')['content'] ?? null;
        $conversation->title ??= $firstQuestion ? Str::limit(trim((string) preg_replace('/\s+/', ' ', $firstQuestion)), 60) : 'New chat';
        $conversation->touch();
        $conversation->save();

        return response()->json(['title' => $conversation->title]);
    }

    public function renameConversation(Request $request, AiConversation $conversation): RedirectResponse
    {
        $this->own($conversation)->update($request->validate(['title' => ['required', 'string', 'max:120']]));

        return back()->with('status', 'Chat renamed.');
    }

    public function destroyConversation(AiConversation $conversation): RedirectResponse
    {
        $this->own($conversation)->delete();

        return redirect()->route('ai.index')->with('status', 'Chat deleted.');
    }

    public function storeToken(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80']]);
        ['plain' => $plain] = AiToken::issue(CurrentUser::get(), $data['name']);

        return redirect()->to(route('ai.index').'#connect')->with('new_ai_token', $plain);
    }

    public function destroyToken(AiToken $token): RedirectResponse
    {
        abort_unless($token->user_id === CurrentUser::get()->id, 404);
        $token->delete();

        return redirect()->to(route('ai.index').'#connect')->with('status', "Token “{$token->name}” revoked. Your AI can no longer use it.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedConnection(Request $request, ?AiConnection $connection = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'provider' => ['required', Rule::in(array_keys(AiConnection::PROVIDERS))],
            'base_url' => ['required', 'url:http,https', 'max:300'],
            'model' => ['nullable', 'string', 'max:200'],
            'api_key' => ['nullable', 'string', 'max:500'],
            'clear_api_key' => ['boolean'],
        ]);

        $attributes = ['name' => $data['name'], 'provider' => $data['provider'], 'base_url' => rtrim($data['base_url'], '/'), 'model' => $data['model'] ?? null];
        // An empty key field keeps the saved key; "remove" clears it.
        if ($request->boolean('clear_api_key')) {
            $attributes['api_key'] = null;
        } elseif (! empty($data['api_key'])) {
            $attributes['api_key'] = $data['api_key'];
        }

        return $attributes;
    }

    private function ownConnection(AiConnection $connection): AiConnection
    {
        abort_unless($connection->user_id === CurrentUser::get()->id, 404);

        return $connection;
    }

    /**
     * Someone else's chat answers 404, administrators included.
     */
    private function own(AiConversation $conversation): AiConversation
    {
        abort_unless($conversation->user_id === CurrentUser::get()->id, 404);

        return $conversation;
    }
}
