{{-- Run button: actions with inputs open a form first (GET), the rest run straight away (POST). --}}
@if ($workflow->inputs())
    <a href="{{ $url }}" class="btn btn-primary"><x-icon name="play" :size="16" /> Run…</a>
@else
    <form method="POST" action="{{ $url }}">
        @csrf
        <button type="submit" class="btn btn-primary"><x-icon name="play" :size="16" /> Run</button>
    </form>
@endif
