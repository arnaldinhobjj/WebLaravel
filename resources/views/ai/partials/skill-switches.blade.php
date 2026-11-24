{{-- On/off switches for one skill: for your own AIs, and (if allowed) for everyone. --}}
<div class="skill-switches">
    <form method="POST" action="{{ route('ai.skills.toggle', str_replace('.', '_', $name)) }}">
        @csrf @method('PUT')
        <input type="hidden" name="for" value="me">
        <input type="hidden" name="on" value="{{ in_array($name, $offByMe, true) ? 1 : 0 }}">
        <button type="submit" class="btn">{{ in_array($name, $offByMe, true) ? 'Switch on for my AIs' : 'Switch off for my AIs' }}</button>
    </form>
    @if ($canManage)
        <form method="POST" action="{{ route('ai.skills.toggle', str_replace('.', '_', $name)) }}">
            @csrf @method('PUT')
            <input type="hidden" name="for" value="everyone">
            <input type="hidden" name="on" value="{{ in_array($name, $offForEveryone, true) ? 1 : 0 }}">
            <button type="submit" class="btn btn-ghost-danger">{{ in_array($name, $offForEveryone, true) ? 'Switch on for everyone' : 'Switch off for everyone' }}</button>
        </form>
    @endif
</div>
