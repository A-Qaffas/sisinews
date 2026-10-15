@if ($paginator->hasPages())
    <nav class="sn-pager" role="navigation" aria-label="{{ __('layout.pagination.label') }}">
        <ul class="sn-pager__list">
            <li>
                @if ($paginator->onFirstPage())
                    <span class="page-link" aria-disabled="true">
                        <svg class="sn-pager__arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m15 18-6-6 6-6"/></svg>
                        {{ __('layout.pagination.previous') }}
                    </span>
                @else
                    <a class="page-link" href="{{ $paginator->previousPageUrl() }}" rel="prev">
                        <svg class="sn-pager__arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m15 18-6-6 6-6"/></svg>
                        {{ __('layout.pagination.previous') }}
                    </a>
                @endif
            </li>

            <li class="sn-pager__status">{{ __('article.page_of', ['page' => $paginator->currentPage(), 'last' => $paginator->lastPage()]) }}</li>

            @foreach ($elements as $element)
                @if (is_string($element))
                    <li class="sn-pager__num"><span class="page-link" aria-disabled="true">{{ $element }}</span></li>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        <li class="sn-pager__num">
                            @if ($page == $paginator->currentPage())
                                <span class="page-link" aria-current="page">{{ $page }}</span>
                            @else
                                <a class="page-link" href="{{ $url }}" aria-label="{{ __('layout.pagination.page', ['page' => $page]) }}">{{ $page }}</a>
                            @endif
                        </li>
                    @endforeach
                @endif
            @endforeach

            <li>
                @if ($paginator->hasMorePages())
                    <a class="page-link" href="{{ $paginator->nextPageUrl() }}" rel="next">
                        {{ __('layout.pagination.next') }}
                        <svg class="sn-pager__arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m9 18 6-6-6-6"/></svg>
                    </a>
                @else
                    <span class="page-link" aria-disabled="true">
                        {{ __('layout.pagination.next') }}
                        <svg class="sn-pager__arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m9 18 6-6-6-6"/></svg>
                    </span>
                @endif
            </li>
        </ul>
    </nav>
@endif
