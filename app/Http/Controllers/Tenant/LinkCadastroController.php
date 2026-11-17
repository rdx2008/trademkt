<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\TipoInstalacao;
use App\Http\Controllers\Controller;
use App\Models\Configuracao;
use App\Support\TermoDaRede;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** O admin da agência liga ou desliga o link de cadastro dos promotores e ajusta o termo. */
class LinkCadastroController extends Controller
{
    private function gerir(Request $request): void
    {
        abort_unless(tenant()->tipo === TipoInstalacao::Agencia && $request->user()->podeGerirPromotores(), 403);
    }

    public function show(Request $request): View
    {
        $this->gerir($request);

        return view('tenant.cadastro.link', [
            'ativo' => CadastroPublicoController::ativo(),
            'link' => route('cadastro.mostrar', tenant('codigo')),
            'termo' => TermoDaRede::texto(),
            'termoPadrao' => TermoDaRede::padrao(),
            'versao' => TermoDaRede::versao(),
        ]);
    }

    public function alternar(Request $request): RedirectResponse
    {
        $this->gerir($request);
        $ligar = $request->boolean('ativo');
        Configuracao::gravar(CadastroPublicoController::CHAVE_ATIVO, $ligar ? '1' : '0');

        return redirect()->route('promotores.link')->with('status', $ligar ? 'Cadastro pelo link ligado.' : 'Cadastro pelo link desligado. Quem abrir o link verá que está indisponível.');
    }

    public function termo(Request $request): RedirectResponse
    {
        $this->gerir($request);
        $texto = trim((string) $request->validate(['termo' => ['nullable', 'string', 'max:6000']])['termo'] ?? '');
        // Em branco ou igual ao padrão volta ao texto padrão.
        Configuracao::gravar(TermoDaRede::CHAVE, $texto === '' || $texto === TermoDaRede::padrao() ? null : $texto);

        return redirect()->route('promotores.link')->with('status', 'Termo atualizado. Quem se cadastrar daqui em diante aceita a nova versão.');
    }
}
