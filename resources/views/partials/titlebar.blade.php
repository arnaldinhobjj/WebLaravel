<header class="titlebar">
    <div class="titlebar-title">
        <span class="app-badge"><x-icon name="logo" :size="15" /></span>
        <h1>Fort Vale</h1>
    </div>

    <div class="titlebar-tools">
        <a class="tool-btn" href="#mentions" title="Notifications">
            <x-icon name="bell" />
            @if (count($mentions))
                <span class="badge">{{ count($mentions) }}</span>
            @endif
            <span class="sr-only">{{ count($mentions) }} unread notifications</span>
        </a>
        <button type="button" class="tool-btn" title="Settings"><x-icon name="settings" /><span class="sr-only">Settings</span></button>
        <button type="button" class="tool-btn" title="More"><x-icon name="more-horizontal" /><span class="sr-only">More</span></button>
        <button type="button" class="tool-btn avatar" title="Account"><x-icon name="user" :size="22" /><span class="sr-only">Account</span></button>

        <div class="window-controls" aria-hidden="true">
            <span><x-icon name="minus" :size="16" /></span>
            <span><x-icon name="square" :size="14" /></span>
            <span class="close"><x-icon name="x" :size="16" /></span>
        </div>
    </div>
</header>

<div class="toolbar">
    <button type="button" class="tool-btn" data-sidebar-toggle aria-controls="sidebar" title="Toggle sidebar">
        <x-icon name="panel-left" :size="18" />
        <span class="sr-only">Toggle sidebar</span>
    </button>

    <label class="search">
        <x-icon name="search" :size="16" />
        <span class="sr-only">Search</span>
        <input type="search" placeholder="Search" autocomplete="off" data-search-input>
        <kbd>Ctrl K</kbd>
    </label>
</div>
