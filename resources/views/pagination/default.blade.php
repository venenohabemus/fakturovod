@if ($paginator->hasPages())
    <nav class="pagination" aria-label="Stránkovanie">
        @if ($paginator->onFirstPage())
            <span class="page-link disabled" aria-hidden="true">‹</span>
        @else
            <a class="page-link" href="{{ $paginator->previousPageUrl() }}" rel="prev">‹</a>
        @endif

        @foreach ($elements as $element)
            @if (is_string($element))
                <span class="page-link disabled">{{ $element }}</span>
            @endif

            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <span class="page-link active" aria-current="page">{{ $page }}</span>
                    @else
                        <a class="page-link" href="{{ $url }}">{{ $page }}</a>
                    @endif
                @endforeach
            @endif
        @endforeach

        @if ($paginator->hasMorePages())
            <a class="page-link" href="{{ $paginator->nextPageUrl() }}" rel="next">›</a>
        @else
            <span class="page-link disabled" aria-hidden="true">›</span>
        @endif
    </nav>
@endif
