@if ($paginator->hasPages())
    <nav class="paginacao-painel" aria-label="Paginação">
        <span class="rotulo">{{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} de {{ $paginator->total() }}</span>
        <div class="acoes">
            @if ($paginator->onFirstPage())<span class="botao secundario desabilitado" aria-disabled="true">Anterior</span>@else<a class="botao secundario" rel="prev" href="{{ $paginator->previousPageUrl() }}">Anterior</a>@endif
            <span class="rotulo">Página {{ $paginator->currentPage() }} de {{ $paginator->lastPage() }}</span>
            @if ($paginator->hasMorePages())<a class="botao secundario" rel="next" href="{{ $paginator->nextPageUrl() }}">Próxima</a>@else<span class="botao secundario desabilitado" aria-disabled="true">Próxima</span>@endif
        </div>
    </nav>
@endif
