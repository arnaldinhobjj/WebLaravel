<aside class="sidebar" id="sidebar" aria-label="Main navigation">
    <a class="brand" href="{{ route('dashboard') }}">
        <span class="brand-mark"><x-icon name="logo" :size="18" /></span>
        <span class="brand-name">Fort&nbsp;Vale</span>
    </a>

    <nav class="nav">
        @foreach ($navigation as $group)
            @include('partials.nav-group', ['group' => $group])
        @endforeach
    </nav>

    <nav class="nav nav-footer" aria-label="Help">
        @include('partials.nav-group', ['group' => $footerNavigation])
    </nav>
</aside>
