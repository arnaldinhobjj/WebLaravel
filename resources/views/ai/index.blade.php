@extends('layouts.app')

@section('title', $conversation?->title ?? 'AI')

@section('content')
    <div class="page-head">
        <div>
            <h2 class="section-title">AI</h2>
            <p class="page-sub">Chat with your own local AI. Your browser talks to it directly; chats are saved here and only you can see them.</p>
        </div>
    </div>

    <div class="ai-layout">
        {{-- Saved chats --}}
        <nav class="card ai-chats" aria-label="Your chats">
            <a href="{{ route('ai.index') }}" class="btn ai-new-chat"><x-icon name="plus" :size="16" /> New chat</a>
            <ul data-ai-chat-list>
                @forelse ($conversations as $chat)
                    <li><a href="{{ route('ai.index', ['c' => $chat->id]) }}" @class(['is-active' => $conversation?->is($chat)])>{{ $chat->title ?? 'New chat' }}</a></li>
                @empty
                    <li class="muted small ai-no-chats">No chats yet.</li>
                @endforelse
            </ul>
        </nav>

        {{-- The conversation --}}
        <section class="card ai-chat" aria-label="Chat"
                 data-ai-chat
                 data-base-url="{{ $settings->base_url }}"
                 data-model="{{ $settings->model }}"
                 data-api-key="{{ $settings->api_key }}"
                 data-system-prompt="{{ $settings->system_prompt }}"
                 data-conversation-id="{{ $conversation?->id }}"
                 data-create-url="{{ route('ai.conversations.store') }}"
                 data-save-url="{{ url('/ai/conversations') }}/{id}/messages">
            <script type="application/json" data-ai-history>@json($history)</script>

            @if ($conversation)
                <div class="ai-chat-head">
                    <details class="menu inline-menu">
                        <summary class="btn">Rename</summary>
                        <form method="POST" action="{{ route('ai.conversations.rename', $conversation) }}" class="menu-list inline-form">
                            @csrf @method('PATCH')
                            <label class="sr-only" for="chat-title">Chat name</label>
                            <input id="chat-title" type="text" name="title" value="{{ $conversation->title }}" required maxlength="120">
                            <button type="submit" class="btn btn-primary">Save</button>
                        </form>
                    </details>
                    <form method="POST" action="{{ route('ai.conversations.destroy', $conversation) }}" data-confirm="Delete this chat?">
                        @csrf @method('DELETE')
                        <button type="submit" class="btn btn-ghost-danger">Delete</button>
                    </form>
                </div>
            @endif

            <ol class="ai-messages" data-ai-messages aria-live="polite">
                @foreach ($history as $message)
                    <li class="ai-message ai-message-{{ $message['role'] }}"><div class="ai-bubble">{{ $message['content'] }}</div></li>
                @endforeach
            </ol>
            <p class="ai-empty muted" data-ai-empty @if ($history->isNotEmpty()) hidden @endif>
                @if ($settings->model)
                    Ask {{ $settings->model }} anything.
                @else
                    Connect your AI below, then start chatting.
                @endif
            </p>
            <p class="field-error" data-ai-error hidden></p>

            <form class="ai-compose" data-ai-form>
                <label class="sr-only" for="ai-input">Message</label>
                <textarea id="ai-input" rows="2" placeholder="Message your AI… (Enter to send, Shift+Enter for a new line)" data-ai-input required></textarea>
                <button type="submit" class="btn btn-primary" data-ai-send>Send</button>
                <button type="button" class="btn" data-ai-stop hidden>Stop</button>
            </form>
        </section>
    </div>

    {{-- Connection to your AI --}}
    <details class="card ai-panel" @if (! $settings->model) open @endif>
        <summary><strong>Connect your AI</strong> <span class="muted small">{{ $settings->model ? $settings->model.' at '.$settings->base_url : 'not set up yet' }}</span></summary>
        <form method="POST" action="{{ route('ai.settings') }}" class="ai-settings" data-ai-settings>
            @csrf @method('PUT')
            <div class="field-row field-row-2">
                <label class="field">
                    <span>Address (OpenAI-compatible)</span>
                    <input type="url" name="base_url" value="{{ old('base_url', $settings->base_url) }}" required data-ai-base-url>
                    <small class="muted">Ollama: http://localhost:11434/v1 · LM Studio: http://localhost:1234/v1</small>
                    @error('base_url') <p class="field-error">{{ $message }}</p> @enderror
                </label>
                <label class="field">
                    <span>Model</span>
                    <span class="ai-model-row">
                        <select name="model" data-ai-model-select>
                            @if ($settings->model)<option value="{{ $settings->model }}" selected>{{ $settings->model }}</option>@endif
                        </select>
                        <button type="button" class="btn" data-ai-test>Test connection</button>
                    </span>
                    <small class="muted" data-ai-test-result aria-live="polite">Test connection lists the models your AI has.</small>
                </label>
                <label class="field">
                    <span>API key <small class="muted">(optional; most local AIs don’t need one)</span>
                    <input type="password" name="api_key" autocomplete="off" placeholder="{{ $settings->api_key ? 'Saved — leave empty to keep it' : '' }}" data-ai-api-key>
                    @if ($settings->api_key)
                        <label class="check"><input type="checkbox" name="clear_api_key" value="1"> Remove the saved key</label>
                    @endif
                </label>
                <label class="field">
                    <span>Instructions for your AI <small class="muted">(optional system prompt)</small></span>
                    <textarea name="system_prompt" rows="3" maxlength="4000">{{ old('system_prompt', $settings->system_prompt) }}</textarea>
                </label>
            </div>
            <div class="form-actions"><button type="submit" class="btn btn-primary">Save connection</button></div>
        </form>
        <div class="ai-help muted small">
            <p><strong>Your AI must allow this page to call it.</strong></p>
            <ul>
                <li><strong>Ollama:</strong> start it with <code>OLLAMA_ORIGINS={{ url('/') }} ollama serve</code> (or set <code>OLLAMA_ORIGINS</code> and restart the Ollama app), then <code>ollama pull llama3.2</code>.</li>
                <li><strong>LM Studio:</strong> Developer tab → start the server, and turn on <em>Enable CORS</em>.</li>
                <li><strong>Others</strong> (llama.cpp, vLLM, Jan…): use their OpenAI-compatible address ending in <code>/v1</code> and allow CORS.</li>
            </ul>
        </div>
    </details>

    {{-- Your AI uses Fort Vale --}}
    @can('ai.connect')
        <details class="card ai-panel" id="connect" @if ($newToken) open @endif>
            <summary><strong>Let your AI use Fort Vale</strong> <span class="muted small">MCP · {{ $tokens->count() }} {{ Str::plural('token', $tokens->count()) }}</span></summary>
            <p class="muted small">Your AI app can look up your tickets and files and run your actions, as you and with your permissions, never more. Add Fort Vale as an MCP server in your AI app with a token below.</p>

            @if ($newToken)
                <div class="ai-new-token" role="alert">
                    <strong>Your new token. Copy it now; it won’t be shown again.</strong>
                    <code data-copy>{{ $newToken }}</code>
                </div>
            @endif

            <div class="ai-mcp-setup">
                <p><strong>Server address:</strong> <code>{{ url('/mcp/fortvale') }}</code> · <strong>Header:</strong> <code>Authorization: Bearer &lt;your token&gt;</code></p>
                <p class="muted small">LM Studio / Claude Desktop (<code>mcp.json</code>):</p>
                <pre class="ai-snippet">{
  "mcpServers": {
    "fortvale": {
      "url": "{{ url('/mcp/fortvale') }}",
      "headers": { "Authorization": "Bearer {{ $newToken ?? '<your token>' }}" }
    }
  }
}</pre>
                <p class="muted small">Your AI gets: search_tickets, get_ticket, create_ticket, reply_to_ticket, search_files, read_file, list_actions, run_action (only those your permissions allow).</p>
            </div>

            <form method="POST" action="{{ route('ai.tokens.store') }}" class="inline-form ai-token-form">
                @csrf
                <label class="sr-only" for="token-name">Token name</label>
                <input id="token-name" type="text" name="name" required maxlength="80" placeholder="e.g. LM Studio on my laptop">
                <button type="submit" class="btn btn-primary">Create token</button>
            </form>

            @if ($tokens->isNotEmpty())
                <div class="table-scroll">
                    <table class="access-table">
                        <thead><tr><th>Token</th><th>Last used</th><th>Recent calls</th><th><span class="sr-only">Actions</span></th></tr></thead>
                        <tbody>
                            @foreach ($tokens as $token)
                                <tr>
                                    <td><strong>{{ $token->name }}</strong><br><span class="muted small">created {{ $token->created_at->diffForHumans() }}</span></td>
                                    <td class="muted">{{ $token->last_used_at?->diffForHumans() ?? 'Never' }}</td>
                                    <td class="small">
                                        @forelse ($token->calls as $call)
                                            <div><code>{{ $call->tool }}</code> <span @class(['muted' => $call->ok, 'field-error' => ! $call->ok])>{{ $call->ok ? 'ok' : $call->summary }}</span> · {{ $call->created_at->diffForHumans() }}</div>
                                        @empty
                                            <span class="muted">None yet</span>
                                        @endforelse
                                    </td>
                                    <td>
                                        <form method="POST" action="{{ route('ai.tokens.destroy', $token) }}" data-confirm="Revoke “{{ $token->name }}”? Your AI will stop being able to use Fort Vale with it.">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="btn btn-ghost-danger">Revoke</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </details>
    @endcan
@endsection
