@if ($verdict === true)
    <span class="pill pill-pass"><x-icon name="check" :size="13" /> Meets spec</span>
@elseif ($verdict === false)
    <span class="pill pill-fail"><x-icon name="x" :size="13" /> Out of spec</span>
@else
    <span class="pill status-closed">No results</span>
@endif
