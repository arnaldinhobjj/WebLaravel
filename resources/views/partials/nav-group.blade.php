<details class="nav-group" @if ($group['open'] ?? false) open @endif>
    <summary class="nav-summary">
        <x-icon name="chevron-right" :size="16" class="nav-chevron" />
        <span>{{ $group['label'] }}</span>
    </summary>
    <ul class="nav-items">
        @foreach ($group['items'] as $item)
            <li>
                <a href="#" class="nav-link @if ($item === 'Home') is-active @endif" @if ($item === 'Home') aria-current="page" @endif>
                    <span class="nav-dot" aria-hidden="true"></span>{{ $item }}
                </a>
            </li>
        @endforeach
    </ul>
</details>
