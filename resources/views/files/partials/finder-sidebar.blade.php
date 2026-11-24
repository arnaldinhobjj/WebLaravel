{{-- The Files (Finder-style) sidebar. $current: 'top', 'library', 'mine', 'trash' or 'trash-mine'. --}}
@php
    $me = auth()->user();
    $library = $me->can('files.view') ? \App\Models\Folder::libraryRoot() : null;
    $mine = $me->can('my_files.view') ? \App\Models\Folder::personalRootFor($me) : null;
@endphp
<nav class="finder-sidebar" aria-label="Places">
    <p class="finder-sidebar-title">Places</p>
    <a href="{{ route('workspace.index') }}" @class(['is-active' => $current === 'top'])><x-icon name="grid" :size="16" /> Files</a>
    @if ($library)
        <a href="{{ route('workspace.folder', $library) }}" @class(['is-active' => $current === 'library'])><x-icon name="inbox" :size="16" /> Library</a>
    @endif
    @if ($mine)
        <a href="{{ route('workspace.folder', $mine) }}" @class(['is-active' => $current === 'mine'])><x-icon name="user" :size="16" /> My files</a>
    @endif
    @canany(['files.delete', 'my_files.delete'])
        <p class="finder-sidebar-title">Trash</p>
        @can('files.delete')
            <a href="{{ route('workspace.trash') }}" @class(['is-active' => $current === 'trash'])><x-icon name="trash" :size="16" /> Library trash</a>
        @endcan
        @can('my_files.delete')
            <a href="{{ route('workspace.trash', ['area' => 'mine']) }}" @class(['is-active' => $current === 'trash-mine'])><x-icon name="trash" :size="16" /> My trash</a>
        @endcan
    @endcanany
</nav>
