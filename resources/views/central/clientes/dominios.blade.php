<x-layout titulo="Endereços do cliente" marca="Painel central">
    <x-slot:nav>@include('central.nav')</x-slot:nav>
    <div class="cabecalho"><div><h1>Endereços de {{ $cliente->nome }}</h1><p class="rotulo">Subdomínio do sistema e domínio próprio podem acessar a mesma instalação.</p></div><a href="{{ route('central.clientes.index') }}" class="botao secundario">Voltar aos clientes</a></div>
    <div class="cartao tabela-rolagem"><table><thead><tr><th>Endereço</th><th>Situação</th><th>Ações</th></tr></thead><tbody>
        @foreach ($cliente->domains as $dominio)
            <tr><td>{{ $dominio->domain }}</td><td>{{ $cliente->dominioPrincipal() === $dominio->domain ? 'Principal' : 'Alternativo' }}</td><td><div class="acoes">
                @if ($cliente->dominioPrincipal() !== $dominio->domain)<form method="POST" action="{{ route('central.dominios.principal', [$cliente, $dominio->id]) }}">@csrf @method('PATCH')<button class="link-botao">Tornar principal</button></form>@endif
                @if ($cliente->domains->count() > 1)<form method="POST" action="{{ route('central.dominios.destroy', [$cliente, $dominio->id]) }}" onsubmit="return confirm('Remover este endereço do sistema? O banco e os dados serão mantidos.')">@csrf @method('DELETE')<button class="link-botao">Remover</button></form>@endif
            </div></td></tr>
        @endforeach
    </tbody></table></div>
    <form method="POST" action="{{ route('central.dominios.store', $cliente) }}" class="secao-painel">@csrf @include('central.clientes.endereco-campos')<button class="botao">Adicionar endereço</button></form>
    <p class="nota-painel">Adicione e teste o endereço novo antes de torná-lo principal ou remover o antigo. Alterar o domínio mantém usuários, contratos, fotos e histórico. Pode ser necessário entrar novamente no endereço novo.</p>
</x-layout>
