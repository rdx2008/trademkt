<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\ModoEmpresa;
use App\Enums\Perfil;
use App\Enums\TipoInstalacao;
use App\Http\Controllers\Controller;
use App\Models\Vinculo;
use App\Services\Rede\Autocadastro;
use App\Services\Rede\EntregaDeCadastro;
use App\Services\Rede\Regioes;
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

    /** A agência cadastra uma indústria que ainda não usa o sistema e passa a operá-la (indústria gerida). */
    public function criarIndustria(Request $request, Autocadastro $autocadastro): RedirectResponse
    {
        $this->exigirGestor($request);
        abort_unless(tenant()->tipo === TipoInstalacao::Agencia, 403);
        $dados = $request->validate(['relacao' => ['required', Rule::in(['prospect', 'cliente'])]]);
        $criada = $autocadastro->criar(tenant(), TipoInstalacao::Industria, ModoEmpresa::Gerida, $request->only(['nome', 'cnpj']));
        $vinculo = $this->vinculos->criar($criada['tenant'], tenant(), null, 'agencia');
        $vinculo->update(['relacao' => $dados['relacao']]);

        return redirect()->route('vinculadas.index')->with('status', 'Indústria "'.$criada['tenant']->nome.'" cadastrada. Escolha-a no seletor do topo para cadastrar PDVs, catálogo e contratos.');
    }

    /** A agência muda a relação comercial (prospect, cliente, encerrado) com uma indústria. */
    public function relacao(Request $request, int $vinculo): RedirectResponse
    {
        $this->exigirGestor($request);
        abort_unless(tenant()->tipo === TipoInstalacao::Agencia, 403);
        $registro = $this->doMeu($vinculo);
        $dados = $request->validate(['relacao' => ['required', Rule::in(array_keys(Vinculo::RELACOES))]]);
        $registro->update(['relacao' => $dados['relacao']]);

        return redirect()->route('vinculadas.index')->with('status', 'Relação com '.$registro->industria->nome.' atualizada.');
    }

    /** A indústria define as regiões (estados e cidades) que cada agência atende. */
    public function regioes(Request $request, int $vinculo, Vinculos $vinculos): RedirectResponse
    {
        $this->exigirGestor($request);
        abort_unless(tenant()->tipo === TipoInstalacao::Industria, 403);
        $registro = $this->doMeu($vinculo);
        $request->validate(['regioes' => ['nullable', 'string', 'max:3000']]);
        $vinculos->atualizar($registro, Regioes::doTexto((string) $request->input('regioes')), $registro->ativo);

        return redirect()->route('vinculadas.index')->with('status', 'Regiões de '.$registro->agencia->nome.' atualizadas.');
    }

    /** A indústria permite ou não que a agência mantenha o catálogo dela. */
    public function permissoes(Request $request, int $vinculo): RedirectResponse
    {
        $this->exigirGestor($request);
        abort_unless(tenant()->tipo === TipoInstalacao::Industria, 403);
        $registro = $this->doMeu($vinculo);
        $registro->update(['permissoes' => ['agencia_mantem_catalogo' => $request->boolean('agencia_mantem_catalogo')] + ($registro->permissoes ?? [])]);

        return redirect()->route('vinculadas.index')->with('status', 'Permissões de '.$registro->agencia->nome.' atualizadas.');
    }

    /** A agência gestora entrega o cadastro à indústria, que passa a ser independente. */
    public function entregar(Request $request, int $vinculo, EntregaDeCadastro $entrega): RedirectResponse
    {
        $this->exigirGestor($request);
        $registro = $this->doMeu($vinculo);
        $link = $entrega->entregar($registro->industria, tenant(), (string) $request->input('admin_nome'), (string) $request->input('admin_email'));

        return redirect()->route('vinculadas.index')
            ->with('status', $registro->industria->nome.' agora é independente. A agência continua vinculada.')
            ->with('convite_industria', ['nome' => $request->input('admin_nome'), 'url' => $link]);
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
