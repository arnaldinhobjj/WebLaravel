<details class="nav-group" @if ($group['open']) open @endif>
    <summary class="nav-summary">
        <x-icon name="chevron-right" :size="16" class="nav-chevron" />
        <span>{{ $group['label'] }}</span>
    </summary>
    <ul class="nav-items">
        @foreach ($group['items'] as $item)
            <li>
                <a href="{{ $item['url'] }}" @class(['nav-link', 'is-active' => $item['active']]) @if ($item['active']) aria-current="page" @endif>
                    <span class="nav-dot" aria-hidden="true"></span>
                    <span class="nav-label">{{ $item['label'] }}</span>
                    @if ($item['badge'])
                        <span class="nav-badge">{{ $item['badge'] }}</span>
                    @endif
                </a>
            </li>
        @endforeach
    </ul>
</details>
