{{--
    YAML editor used for personal and shared actions.
    Expects: $formAction, $method ('POST' or 'PUT'), $yaml, $submitLabel, $cancelUrl, optional $hidden (name => value)
    and $readonly (show the YAML without saving).
--}}
<div class="yaml-layout">
    <form method="POST" action="{{ $formAction }}" class="card yaml-form">
        @csrf
        @if ($method !== 'POST')
            @method($method)
        @endif
        @foreach ($hidden ?? [] as $name => $value)
            <input type="hidden" name="{{ $name }}" value="{{ old($name, $value) }}">
        @endforeach

        @if ($errors->has('yaml'))
            <div class="yaml-errors-box" role="alert">
                <strong>Fix {{ count($errors->get('yaml')) }} {{ Str::plural('problem', count($errors->get('yaml'))) }} before saving:</strong>
                <ul class="yaml-errors">
                    @foreach ($errors->get('yaml') as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <label class="sr-only" for="yaml">Action YAML</label>
        <textarea id="yaml" name="yaml" class="yaml-editor" rows="24" spellcheck="false" autocapitalize="off" autocomplete="off"
                  data-yaml-editor @readonly($readonly ?? false) @error('yaml') aria-invalid="true" @enderror>{{ old('yaml', $yaml) }}</textarea>

        <div class="form-actions">
            <a href="{{ $cancelUrl }}" class="btn">{{ ($readonly ?? false) ? 'Back' : 'Cancel' }}</a>
            @unless ($readonly ?? false)
                <button type="submit" class="btn btn-primary">{{ $submitLabel }}</button>
            @endunless
        </div>
    </form>

    <aside class="card yaml-help" aria-labelledby="yaml-help-title">
        <h3 id="yaml-help-title" class="sub-title">Quick reference</h3>
        <dl>
            <dt><code>on: run</code></dt>
            <dd>A Run button. <code>show_on_home: true</code> adds it to the home screen; <code>inputs:</code> asks for values first.</dd>
            <dt><code>on: ticket</code></dt>
            <dd><code>types: [created, status_changed, assigned, replied]</code>, optionally <code>if:</code> category, priority, status or assignee. Personal actions run only for tickets you opened or are assigned to.</dd>
            <dt><code>on: schedule</code></dt>
            <dd><code>- cron: "0 9 * * 1"</code> (Mondays 09:00).</dd>
            <dt>Steps (<code>uses:</code>)</dt>
            <dd>
                <code>ticket/create</code>, <code>ticket/update</code>, <code>ticket/reply</code>, <code>http/request</code>, <code>message/show</code>,
                and as the last step of a Run-button action <code>page/open</code> or <code>ticket/open-form</code>.
            </dd>
            <dt>Values</dt>
            <dd><code>${{ '{{' }} inputs.name }}</code>, <code>${{ '{{' }} ticket.id }}</code>, <code>${{ '{{' }} actor.name }}</code>, <code>${{ '{{' }} steps.&lt;id&gt;.ticket }}</code>; shared actions also <code>${{ '{{' }} secrets.NAME }}</code></dd>
        </dl>
        <p class="muted small">The full reference is in the README, under “Actions (YAML)”.</p>
    </aside>
</div>
