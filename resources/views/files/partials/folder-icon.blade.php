{{-- A Finder-style folder. $tone: normal, shared, personal or locked (limited to some people). --}}
<span class="finder-folder finder-folder-{{ $tone ?? 'normal' }}" aria-hidden="true">
    <svg viewBox="0 0 64 52" width="64" height="52">
        <path class="back" d="M4 6a4 4 0 0 1 4-4h14.5a4 4 0 0 1 3 1.4L29 7.5h27a4 4 0 0 1 4 4V46a4 4 0 0 1-4 4H8a4 4 0 0 1-4-4z"/>
        <path class="front" d="M2 16a4 4 0 0 1 4-4h52a4 4 0 0 1 4 4v30a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4z"/>
        @if (($tone ?? '') === 'locked')
            <g class="badge"><rect x="26" y="28" width="12" height="10" rx="2"/><path d="M28.5 28v-3a3.5 3.5 0 0 1 7 0v3" fill="none" stroke-width="2"/></g>
        @elseif (($tone ?? '') === 'personal')
            <g class="badge"><circle cx="32" cy="27" r="4"/><path d="M24 39a8 8 0 0 1 16 0z"/></g>
        @elseif (($tone ?? '') === 'shared')
            <g class="badge"><circle cx="27" cy="28" r="3.5"/><circle cx="37" cy="28" r="3.5"/><path d="M20 39a7 7 0 0 1 14 0zM30 39a7 7 0 0 1 14 0z"/></g>
        @endif
    </svg>
</span>
