<?php

namespace App\Http\Controllers;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiSetting;
use App\Models\AiToken;
use App\Support\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * The /ai page: chat with your own local AI (your browser talks to it
 * directly), your saved chats, your connection settings, and tokens that let
 * your AI use Fort Vale. Everything here belongs to one person only.
 */
class AiController extends Controller
{
    public function index(Request $request): View
    {
        $me = CurrentUser::get();
        $conversation = $request->integer('c') ? $this->own(AiConversation::findOrFail($request->integer('c'))) : null;

        return view('ai.index', [
            'settings' => AiSetting::for($me),
            'conversations' => AiConversation::where('user_id', $me->id)->latest('updated_at')->latest('id')->limit(100)->get(),
            'conversation' => $conversation,
            'history' => $conversation?->messages->map(fn (AiMessage $message) => ['role' => $message->role, 'content' => $message->content])->values() ?? collect(),
            'tokens' => Gate::allows('ai.connect') ? AiToken::where('user_id', $me->id)->with(['calls' => fn ($q) => $q->limit(5)])->latest('id')->get() : collect(),
            'newToken' => session('new_ai_token'),
        ]);
    }

    public function saveSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'base_url' => ['required', 'url:http,https', 'max:300'],
            'model' => ['nullable', 'string', 'max:200'],
            'api_key' => ['nullable', 'string', 'max:500'],
            'clear_api_key' => ['boolean'],
            'system_prompt' => ['nullable', 'string', 'max:4000'],
        ]);

        $settings = AiSetting::for(CurrentUser::get());
        $settings->fill([
            'base_url' => rtrim($data['base_url'], '/'),
            'model' => $data['model'] ?? null,
            'system_prompt' => $data['system_prompt'] ?? null,
        ]);
        // An empty key field keeps the saved key; "remove" clears it.
        if ($request->boolean('clear_api_key')) {
            $settings->api_key = null;
        } elseif (! empty($data['api_key'])) {
            $settings->api_key = $data['api_key'];
        }
        $settings->save();

        return back()->with('status', 'AI connection saved.');
    }

    public function storeConversation(): JsonResponse
    {
        $conversation = AiConversation::create(['user_id' => CurrentUser::get()->id]);

        return response()->json(['id' => $conversation->id, 'url' => route('ai.index', ['c' => $conversation->id])], 201);
    }

    /**
     * Saves one exchange: your message and the AI's answer.
     */
    public function storeMessages(Request $request, AiConversation $conversation): JsonResponse
    {
        $this->own($conversation);
        $data = $request->validate([
            'user' => ['required', 'string', 'max:50000'],
            'assistant' => ['present', 'nullable', 'string', 'max:200000'],
            'model' => ['nullable', 'string', 'max:200'],
        ]);

        $conversation->messages()->create(['role' => 'user', 'content' => $data['user']]);
        if (($data['assistant'] ?? '') !== '') {
            $conversation->messages()->create(['role' => 'assistant', 'content' => $data['assistant'], 'model' => $data['model'] ?? null]);
        }

        $conversation->title ??= Str::limit(trim((string) preg_replace('/\s+/', ' ', $data['user'])), 60);
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
     * Someone else's chat answers 404, administrators included.
     */
    private function own(AiConversation $conversation): AiConversation
    {
        abort_unless($conversation->user_id === CurrentUser::get()->id, 404);

        return $conversation;
    }
}
