{{-- Left side of the file pages: areas, trash and search. --}}
<nav class="card files-nav" aria-label="File areas">
    <form method="GET" action="{{ route('files.search') }}" role="search" class="files-search">
        <label class="sr-only" for="files-q">Search files</label>
        <input id="files-q" type="search" name="q" value="{{ request()->routeIs('files.search') ? request('q') : '' }}" placeholder="Search files">
    </form>
    <ul>
        @can('files.view')
            <li><a href="{{ route('files.library') }}" @class(['is-active' => $area === 'library'])><x-icon name="inbox" :size="16" /> Library</a></li>
        @endcan
        @can('my_files.view')
            <li><a href="{{ route('files.mine') }}" @class(['is-active' => $area === 'mine'])><x-icon name="user" :size="16" /> My files</a></li>
        @endcan
        @can('files.delete')
            <li><a href="{{ route('files.trash') }}" @class(['is-active' => $area === 'trash'])><x-icon name="trash" :size="16" /> Library trash</a></li>
        @endcan
        @can('my_files.delete')
            <li><a href="{{ route('files.trash', ['area' => 'mine']) }}" @class(['is-active' => $area === 'trash-mine'])><x-icon name="trash" :size="16" /> My trash</a></li>
        @endcan
        @can('file_settings.view')
            <li><a href="{{ route('file-settings.edit') }}" @class(['is-active' => $area === 'settings'])><x-icon name="settings" :size="16" /> File settings</a></li>
        @endcan
    </ul>
</nav>
