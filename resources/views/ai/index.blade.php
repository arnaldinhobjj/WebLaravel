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
                 data-conversation-id="{{ $conversation?->id }}"
                 data-system-prompt="{{ $settings->system_prompt }}"
                 data-create-url="{{ route('ai.conversations.store') }}"
                 data-save-url="{{ url('/ai/conversations') }}/{id}/messages"
                 data-run-url="{{ url('/ai/skills') }}/{name}/run"
                 data-recipe-url="{{ url('/ai/recipes') }}/{name}">
            <script type="application/json" data-ai-history>@json($history)</script>
            <script type="application/json" data-ai-skills>@json($skills)</script>
            {{-- Your connections, with keys, for your own browser only. --}}
            <script type="application/json" data-ai-connections>@json($browserConnections)</script>

            <div class="ai-chat-head">
                <label class="ai-connection-pick">
                    <span class="muted small">Talking to</span>
                    <select data-ai-connection>
                        @forelse ($connections as $option)
                            <option value="{{ $option->id }}" @selected($connection?->is($option))>{{ $option->name }}{{ $option->model ? ' · '.$option->model : '' }}</option>
                        @empty
                            <option value="">No AI connected yet</option>
                        @endforelse
                    </select>
                </label>
                @if ($conversation)
                    <span class="ai-chat-tools">
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
                    </span>
                @endif
            </div>

            <ol class="ai-messages" data-ai-messages aria-live="polite"></ol>
            <p class="ai-empty muted" data-ai-empty @if ($history->isNotEmpty()) hidden @endif>
                @if ($connection)
                    Ask anything. Type <kbd>/</kbd> to use a Fort Vale skill or recipe.
                @else
                    Add an AI below (local or cloud), then start chatting.
                @endif
            </p>
            <p class="field-error" data-ai-error hidden></p>

            <form class="ai-skill-form card" data-skill-form hidden></form>

            <div class="ai-compose-wrap">
            <div class="ai-skill-menu card" data-skill-menu hidden>
                <p class="ai-skill-menu-head muted small" data-skill-menu-head>Skills and recipes</p>
                <ul id="skill-list" data-skill-list role="listbox" aria-label="Skills"></ul>
            </div>
            <form class="ai-compose" data-ai-form>
                <label class="sr-only" for="ai-input">Message</label>
                <textarea id="ai-input" rows="2" placeholder="Message your AI… Type / to use a skill. Enter sends, Shift+Enter adds a line." data-ai-input
                          aria-describedby="ai-compose-hint" aria-controls="skill-list" aria-autocomplete="list"></textarea>
                <button type="submit" class="btn btn-primary" data-ai-send>Send</button>
                <button type="button" class="btn" data-ai-stop hidden>Stop</button>
            </form>
            <p id="ai-compose-hint" class="ai-compose-hint muted small">Type <kbd>/</kbd> for skills: keep typing to narrow them down, ↑ ↓ to choose, Enter to pick. <a href="{{ route('ai.skills') }}">All skills</a></p>
            </div>
        </section>
    </div>

    {{-- Your AIs: local or cloud --}}
    <details class="card ai-panel" @if ($connections->isEmpty()) open @endif>
        <summary><strong>Your AIs</strong> <span class="muted small">{{ $connections->count() }} connected · local (Ollama, LM Studio…) or cloud (OpenAI, Claude, Gemini…)</span></summary>

        @foreach ($connections as $item)
            <details class="ai-connection" @if (! $item->model) open @endif>
                <summary><strong>{{ $item->name }}</strong> <span class="muted small">{{ \App\Models\AiConnection::PROVIDERS[$item->provider] }} · {{ $item->model ?? 'no model chosen' }} · {{ $item->base_url }}</span></summary>
                @include('ai.partials.connection-form', ['item' => $item])
                <form method="POST" action="{{ route('ai.connections.destroy', $item) }}" data-confirm="Remove “{{ $item->name }}”? Chats with it are kept.">
                    @csrf @method('DELETE')
                    <button type="submit" class="btn btn-ghost-danger">Remove</button>
                </form>
            </details>
        @endforeach

        <details class="ai-connection" @if ($connections->isEmpty()) open @endif>
            <summary><strong>Add an AI</strong></summary>
            @include('ai.partials.connection-form', ['item' => null])
        </details>

        <div class="ai-help muted small">
            <p><strong>Local AIs must allow this page to call them</strong> (cloud AIs already do; your key is stored encrypted and only sent to your own browser):</p>
            <ul>
                <li><strong>Ollama:</strong> <code>OLLAMA_ORIGINS={{ url('/') }} ollama serve</code>, then e.g. <code>ollama pull qwen2.5:7b</code> (good at using skills).</li>
                <li><strong>LM Studio:</strong> Developer tab → start the server, turn on <em>Enable CORS</em>.</li>
                <li><strong>Skills</strong> work with models that support tool calling (most current ones: Llama 3.1+, Qwen 2.5+, GPT-4o, Claude, Gemini…). Others still chat, without skills.</li>
            </ul>
        </div>
    </details>

    <details class="card ai-panel">
        <summary><strong>Instructions for your AIs</strong> <span class="muted small">{{ $settings->system_prompt ? 'set' : 'none' }} · <a href="{{ route('ai.skills') }}">skills</a></span></summary>
        <form method="POST" action="{{ route('ai.settings') }}" class="ai-settings">
            @csrf @method('PUT')
            <label class="field">
                <span>Sent at the start of every chat (optional)</span>
                <textarea name="system_prompt" rows="3" maxlength="4000">{{ old('system_prompt', $settings->system_prompt) }}</textarea>
            </label>
            <div class="form-actions"><button type="submit" class="btn btn-primary">Save instructions</button></div>
        </form>
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

@push('scripts')
    <script src="{{ asset('js/ai-chat.js') }}?v={{ filemtime(public_path('js/ai-chat.js')) }}" defer></script>
@endpush
