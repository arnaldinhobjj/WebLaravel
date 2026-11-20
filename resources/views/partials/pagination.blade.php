@if ($paginator->hasPages())
    <nav class="pagination" aria-label="Pages">
        @if ($paginator->onFirstPage())
            <span class="btn is-disabled" aria-disabled="true">Previous</span>
        @else
            <a class="btn" href="{{ $paginator->previousPageUrl() }}" rel="prev">Previous</a>
        @endif

        <span class="muted">Page {{ $paginator->currentPage() }} of {{ $paginator->lastPage() }} · {{ $paginator->total() }} tickets</span>

        @if ($paginator->hasMorePages())
            <a class="btn" href="{{ $paginator->nextPageUrl() }}" rel="next">Next</a>
        @else
            <span class="btn is-disabled" aria-disabled="true">Next</span>
        @endif
    </nav>
@endif
