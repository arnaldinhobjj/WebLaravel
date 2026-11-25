{{-- A table widget: the columns and rows its table worked out for the viewer. --}}
@if ($data['summary'] !== '')
    <p class="muted small">{{ $data['summary'] }}</p>
@endif

@if ($data['rows'] === [])
    <p class="muted">Nothing here right now.</p>
@else
    <div class="dashboard-table-wrap">
        <table class="dashboard-table">
            <thead>
                <tr>
                    @foreach ($data['columns'] as $label)
                        <th scope="col">{{ $label }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($data['rows'] as $row)
                    <tr>
                        @foreach ($row['cells'] as $cell)
                            <td>
                                @if ($loop->first && $row['url'])
                                    <a href="{{ $row['url'] }}">{{ $cell }}</a>
                                @else
                                    {{ $cell }}
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

@if ($data['url'])
    <p><a href="{{ $data['url'] }}" class="link-btn">View all</a></p>
@endif
