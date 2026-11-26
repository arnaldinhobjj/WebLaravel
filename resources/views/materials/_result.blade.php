@if ($passed === true)
    <span class="result pass" title="Meets spec"><x-icon name="check" :size="16" /><span class="sr-only">Meets spec</span></span>
@elseif ($passed === false)
    <span class="result fail" title="Out of spec"><x-icon name="x" :size="16" /><span class="sr-only">Out of spec</span></span>
@endif
