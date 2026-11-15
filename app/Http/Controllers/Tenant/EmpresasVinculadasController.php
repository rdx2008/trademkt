<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\Perfil;
use App\Enums\TipoInstalacao;
use App\Http\Controllers\Controller;
use App\Models\Vinculo;
use App\Services\Rede\Vinculos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Empresas vinculadas (fase 9): a indústria pede vínculo a uma agência que já usa o sistema, e a
 * agência pede a uma indústria, pelo CNPJ. A outra ponta aceita ou recusa; quem pediu pode cancelar
 * enquanto estiver pendente. Admin da instalação (e, na agência, o admin da agência).
 */
class EmpresasVinculadasController extends Controller
{
    public function __construct(private Vinculos $vinculos) {}

    private function exigirGestor(Request $request): void
    {
        $perfil = $request->user()->perfil;
        abort_unless($perfil === Perfil::AdminInstalacao || (tenant()->tipo === TipoInstalacao::Agencia && $perfil === Perfil::AdminAgencia), 403);
    }

    private function doMeu(int $id): Vinculo
    {
        $eu = tenant('id');

        return Vinculo::with(['industria', 'agencia'])
            ->where(fn ($q) => $q->where('industria_tenant_id', $eu)->orWhere('agencia_tenant_id', $eu))
            ->findOrFail($id);
    }

    public function index(Request $request): View
    {
        $this->exigirGestor($request);
        $empresa = tenant();
        $coluna = $empresa->tipo === TipoInstalacao::Industria ? 'industria_tenant_id' : 'agencia_tenant_id';
        $vinculos = Vinculo::with(['industria', 'agencia'])->where($coluna, $empresa->id)->latest('updated_at')->get();

        return view('tenant.vinculadas.index', [
            'souIndustria' => $empresa->tipo === TipoInstalacao::Industria,
            'recebidos' => $vinculos->filter(fn (Vinculo $v) => $this->vinculos->decide($v, $empresa))->values(),
            'enviados' => $vinculos->filter(fn (Vinculo $v) => $v->status === Vinculo::PENDENTE && ! $this->vinculos->decide($v, $empresa))->values(),
            'vinculos' => $vinculos->where('status', '!=', Vinculo::PENDENTE)->values(),
        ]);
    }

    public function pedir(Request $request): RedirectResponse
    {
        $this->exigirGestor($request);
        $dados = $request->validate([
            'cnpj' => ['required', 'string', 'max:18'],
            'relacao' => ['nullable', Rule::in(array_keys(Vinculo::RELACOES))],
        ]);
        $vinculo = $this->vinculos->pedir(tenant(), $dados['cnpj'], $dados['relacao'] ?? 'cliente');
        $outra = tenant()->tipo === TipoInstalacao::Industria ? $vinculo->agencia : $vinculo->industria;

        return redirect()->route('vinculadas.index')->with('status', 'Pedido enviado para '.$outra->nome.'. O vínculo vale quando ela aceitar.');
    }

    public function aceitar(Request $request, int $vinculo): RedirectResponse
    {
        $this->exigirGestor($request);
        $registro = $this->doMeu($vinculo);
        abort_unless($this->vinculos->decide($registro, tenant()), 403);
        $permissoes = tenant()->tipo === TipoInstalacao::Industria
            ? ['agencia_mantem_catalogo' => $request->boolean('agencia_mantem_catalogo')]
            : [];
        $this->vinculos->aceitar($registro, $permissoes);

        return redirect()->route('vinculadas.index')->with('status', 'Vínculo aceito.');
    }

    public function recusar(Request $request, int $vinculo): RedirectResponse
    {
        $this->exigirGestor($request);
        $registro = $this->doMeu($vinculo);
        abort_unless($this->vinculos->decide($registro, tenant()), 403);
        $this->vinculos->recusar($registro);

        return redirect()->route('vinculadas.index')->with('status', 'Pedido recusado.');
    }

    public function cancelar(Request $request, int $vinculo): RedirectResponse
    {
        $this->exigirGestor($request);
        $registro = $this->doMeu($vinculo);
        abort_unless($registro->status === Vinculo::PENDENTE && ! $this->vinculos->decide($registro, tenant()), 403);
        $registro->delete();

        return redirect()->route('vinculadas.index')->with('status', 'Pedido cancelado.');
    }
}
