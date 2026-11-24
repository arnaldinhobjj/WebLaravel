{{-- Add or edit one AI connection. $item: an AiConnection, or null to add. --}}
@php $key = $item ? 'connection-'.$item->id : 'connection-new'; @endphp
<form method="POST" action="{{ $item ? route('ai.connections.update', $item) : route('ai.connections.store') }}" class="ai-settings" data-ai-connection-form>
    @csrf
    @if ($item) @method('PUT') @endif
    @unless ($item)
        <label class="field">
            <span>Start from</span>
            <select data-ai-preset>
                <option value="">Choose…</option>
                @foreach (\App\Models\AiConnection::PRESETS as $label => [$provider, $url])
                    <option value="{{ $url }}" data-provider="{{ $provider }}" data-name="{{ $label }}">{{ $label }}</option>
                @endforeach
            </select>
        </label>
    @endunless
    <div class="field-row field-row-2">
        <label class="field">
            <span>Name</span>
            <input type="text" name="name" value="{{ $item?->name }}" required maxlength="60" placeholder="e.g. Ollama on my Mac" data-ai-name>
        </label>
        <label class="field">
            <span>Kind</span>
            <select name="provider" data-ai-provider>
                @foreach (\App\Models\AiConnection::PROVIDERS as $value => $label)
                    <option value="{{ $value }}" @selected(($item?->provider ?? 'openai') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label class="field">
            <span>Address</span>
            <input type="url" name="base_url" value="{{ $item?->base_url }}" required maxlength="300" placeholder="http://localhost:11434/v1" data-ai-base-url>
        </label>
        <label class="field">
            <span>API key <small class="muted">(cloud AIs; most local ones need none)</small></span>
            <input type="password" name="api_key" autocomplete="off" placeholder="{{ $item?->api_key ? 'Saved — leave empty to keep it' : '' }}" data-ai-api-key data-saved-key="{{ $item?->api_key }}">
            @if ($item?->api_key)
                <label class="check"><input type="checkbox" name="clear_api_key" value="1"> Remove the saved key</label>
            @endif
        </label>
        <label class="field">
            <span>Model</span>
            <span class="ai-model-row">
                <select name="model" data-ai-model-select>
                    @if ($item?->model)<option value="{{ $item->model }}" selected>{{ $item->model }}</option>@endif
                </select>
                <button type="button" class="btn" data-ai-test>Test connection</button>
            </span>
            <small class="muted" data-ai-test-result aria-live="polite">Test connection lists the models you can use.</small>
        </label>
    </div>
    <div class="form-actions"><button type="submit" class="btn btn-primary">{{ $item ? 'Save' : 'Add this AI' }}</button></div>
</form>
