@extends('layouts.app')

@section('title', 'AI skills')

@php
    $status = function (string $name) use ($available, $offByMe, $offForEveryone): array {
        return match (true) {
            in_array($name, $offForEveryone, true) => ['Off for everyone', 'priority-urgent'],
            in_array($name, $offByMe, true) => ['Off for your AIs', 'status-waiting'],
            isset($available[$name]) => ['On', 'status-resolved'],
            default => ['Needs a permission you don’t have', ''],
        };
    };
@endphp

@section('content')
    <a href="{{ route('ai.index') }}" class="back-link"><x-icon name="arrow-left" :size="16" /> AI</a>

    <div class="page-head">
        <div>
            <h2 class="section-title">AI skills</h2>
            <p class="page-sub">What any AI you connect can do in Fort Vale: the /ai chat, MCP apps (Claude, Cursor, LM Studio…) and anything using the OpenAI-style API. Skills always act as you, within your permissions.</p>
        </div>
    </div>

    @if ($tried)
        <div @class(['card', 'skill-result', 'is-error' => ! $tried['ok']]) role="status" id="result">
            <strong>{{ $tried['name'] }}:</strong> {{ $tried['summary'] }}
            @if (! empty($tried['data']))
                <pre>{{ json_encode($tried['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
            @endif
        </div>
    @endif

    <nav class="skill-index" aria-label="Skill areas">
        @foreach ($abilities as $area => $skills)
            <a href="#area-{{ Str::slug($area) }}">{{ $area }} ({{ $skills->count() }})</a>
        @endforeach
        <a href="#recipes">Recipes ({{ count($recipes) }})</a>
    </nav>

    @foreach ($abilities as $area => $skills)
        <section class="access-section" id="area-{{ Str::slug($area) }}" aria-labelledby="area-title-{{ Str::slug($area) }}">
            <h3 class="sub-title" id="area-title-{{ Str::slug($area) }}">{{ $area }}</h3>
            <div class="skill-list">
                @foreach ($skills as $skill)
                    @php [$label, $tone] = $status($skill->name()); @endphp
                    <details class="card skill" id="{{ $skill->toolName() }}" @if (($tried['name'] ?? null) === $skill->name()) open @endif>
                        <summary>
                            <span class="skill-name"><strong>{{ $skill->title() }}</strong> <code>{{ $skill->toolName() }}</code></span>
                            <span class="skill-badges">
                                @if ($skill->changesThings())<span class="pill">Changes things</span>@endif
                                <span class="pill {{ $tone }}">{{ $label }}</span>
                            </span>
                        </summary>
                        <p>{{ $skill->description() }}</p>
                        <p class="muted small">
                            Needs: {{ collect($skill->permissions())->map(fn ($p) => \App\Support\PagePermissions::all()[$p] ?? $p)->join(', ') ?: 'see description (any of a page’s view permissions)' }}
                        </p>
                        @include('ai.partials.skill-switches', ['name' => $skill->name()])

                        @if (isset($available[$skill->name()]))
                            <form method="POST" action="{{ route('ai.skills.run', $skill->toolName()) }}" class="skill-try">
                                @csrf
                                <p class="muted small"><strong>Try it</strong>: runs as you{{ $skill->changesThings() ? ' and really makes the change' : '' }}.</p>
                                <div class="field-row field-row-2">
                                    @foreach ($skill->properties() as $input => $property)
                                        <label class="field">
                                            <span>{{ $input }}@if (in_array($input, $skill->required(), true)) <small>(required)</small>@endif</span>
                                            @if (isset($property['enum']))
                                                <select name="input[{{ $input }}]"><option value=""></option>@foreach ($property['enum'] as $option)<option>{{ $option }}</option>@endforeach</select>
                                            @elseif (($property['type'] ?? '') === 'boolean')
                                                <select name="input[{{ $input }}]"><option value=""></option><option value="1">yes</option><option value="0">no</option></select>
                                            @elseif (($property['maxLength'] ?? 0) > 500 || ($property['type'] ?? '') === 'object')
                                                <textarea name="input[{{ $input }}]" rows="3" placeholder="{{ ($property['type'] ?? '') === 'object' ? '{"name": "value"}' : '' }}"></textarea>
                                            @else
                                                <input type="{{ ($property['type'] ?? '') === 'integer' ? 'number' : 'text' }}" name="input[{{ $input }}]" placeholder="{{ ($property['type'] ?? '') === 'array' ? 'separate with commas' : '' }}">
                                            @endif
                                            @if (! empty($property['description']))<small class="muted">{{ $property['description'] }}</small>@endif
                                        </label>
                                    @endforeach
                                </div>
                                <div><button type="submit" class="btn" @if ($skill->changesThings()) data-confirm-click="“{{ $skill->title() }}” really makes the change, as you. Go ahead?" @endif>Run</button></div>
                            </form>
                        @endif
                    </details>
                @endforeach
            </div>
        </section>
    @endforeach

    <section class="access-section" id="recipes" aria-labelledby="recipes-title">
        <h3 class="sub-title" id="recipes-title">Recipes</h3>
        <p class="muted small">Ready-made instructions an AI follows using the skills above. In the chat, type <kbd>/</kbd> to use one. MCP apps see them as prompts.</p>
        <div class="skill-list">
            @foreach ($recipes as $recipe)
                @php [$label, $tone] = $status($recipe->name()); @endphp
                <details class="card skill" id="{{ $recipe->toolName() }}" @if (($tried['name'] ?? null) === $recipe->name()) open @endif>
                    <summary>
                        <span class="skill-name"><strong>{{ $recipe->title() }}</strong> <code>{{ $recipe->toolName() }}</code></span>
                        <span class="skill-badges"><span class="pill {{ $tone }}">{{ $label }}</span></span>
                    </summary>
                    <p>{{ $recipe->description() }}</p>
                    <p class="muted small">Uses: {{ collect($recipe->uses())->map(fn ($n) => str_replace('.', '_', $n))->join(', ') }}</p>
                    @include('ai.partials.skill-switches', ['name' => $recipe->name()])
                    @if (isset($available[$recipe->name()]))
                        <form method="POST" action="{{ route('ai.recipes.show', $recipe->toolName()) }}" class="skill-try">
                            @csrf
                            <div class="field-row field-row-2">
                                @foreach ($recipe->arguments() as $argument => $definition)
                                    <label class="field">
                                        <span>{{ $argument }}@if ($definition['required']) <small>(required)</small>@endif</span>
                                        <input type="text" name="arguments[{{ $argument }}]">
                                        <small class="muted">{{ $definition['description'] }}</small>
                                    </label>
                                @endforeach
                            </div>
                            <div><button type="submit" class="btn">Show the instructions</button></div>
                        </form>
                    @endif
                </details>
            @endforeach
        </div>
    </section>
@endsection
