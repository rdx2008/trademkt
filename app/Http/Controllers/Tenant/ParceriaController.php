<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\Perfil;
use App\Enums\TipoInstalacao;
use App\Http\Controllers\Controller;
use App\Models\Rede\AgenciaRede;
use App\Models\Rede\Parceria;
use App\Services\Rede\Parcerias;
use App\Services\Rede\PerfilAgencia;
use App\Services\Rede\RegioesParceria;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ParceriaController extends Controller
{
    public function index(Request $request, Parcerias $servico)
    {
        $usuario = $request->user();
        if (tenant()->tipo === TipoInstalacao::Agencia) {
            // Mesmo sem perfil publicado, o administrador recebe uma orientação clara.
            $perfil = AgenciaRede::where('tenant_id', tenant('id'))->first();
            if (! $perfil) {
                abort_unless($usuario->perfil === Perfil::AdminInstalacao, 403);

                return view('tenant.parcerias.agencia', ['perfil' => null, 'solicitacoes' => collect()]);
            }
            $perfil = $servico->agenciaDoUsuario($usuario);

            return view('tenant.parcerias.agencia', ['perfil' => $perfil->load('regioes'), 'solicitacoes' => Parceria::where('agencia_rede_id', $perfil->id)->with('industria')->latest('updated_at')->paginate(15)]);
        }
        $industria = $servico->industriaDoUsuario($usuario);
        $filtros = $request->validate(['uf' => ['nullable', Rule::in(PerfilAgencia::UFS)], 'cidade' => ['nullable', 'string', 'max:100']]);
        if (! empty($filtros['cidade']) && empty($filtros['uf'])) {
            throw ValidationException::withMessages(['uf' => 'Selecione o estado para buscar por município.']);
        }
        $agencias = AgenciaRede::where('aprovada', true)->where('disponivel', true)
            ->whereHas('tenant', fn ($q) => $q->where('ativo', true)->where('tipo', 'agencia'))
            ->when(! empty($filtros['uf']), fn ($q) => $q->whereHas('regioes', fn ($r) => $r->where('uf', $filtros['uf'])
                ->when(! empty($filtros['cidade']), fn ($c) => $c->where(fn ($m) => $m->where('cidade_busca', '')->orWhere('cidade_busca', Str::lower(Str::ascii(trim($filtros['cidade']))))))))
            ->with(['tenant', 'regioes'])->orderBy('id')->paginate(9)->withQueryString();

        return view('tenant.parcerias.industria', ['agencias' => $agencias, 'parcerias' => Parceria::where('industria_tenant_id', tenant('id'))->where('industria_local_id', $industria)->with('agencia.tenant')->latest('updated_at')->get(), 'ufs' => PerfilAgencia::UFS]);
    }

    public function solicitar(Request $request, Parcerias $servico)
    {
        $industria = $servico->industriaDoUsuario($request->user());
        $dados = $request->validate(['agencia_rede_id' => ['required', 'integer'], 'mensagem' => ['nullable', 'string', 'max:2000']]);
        $regioes = RegioesParceria::doRequest($request);
        $servico->solicitar(tenant(), $industria, $request->user()->id, $dados['agencia_rede_id'], '', $dados['mensagem'] ?? null, regioes: $regioes);

        return redirect()->route('parcerias.index')->with('status', 'Solicitação enviada. Aguarde a resposta da agência.');
    }

    public function responder(Request $request, Parceria $parceria, Parcerias $servico)
    {
        $perfil = $servico->agenciaDoUsuario($request->user());
        $dados = $request->validate(['decisao' => ['required', Rule::in(['aceita', 'recusada'])], 'resposta' => ['nullable', 'required_if:decisao,recusada', 'string', 'max:2000']]);
        $servico->responder($parceria, $perfil, $request->user(), $dados['decisao'], $dados['resposta'] ?? null);

        return back()->with('status', $dados['decisao'] === 'aceita' ? 'Parceria aceita. Os contratos de PDVs são definidos separadamente.' : 'Solicitação recusada.');
    }

    public function regiao(Request $request, Parceria $parceria, Parcerias $servico)
    {
        $industria = $servico->industriaDoUsuario($request->user());
        abort_unless($parceria->industria_tenant_id === tenant('id') && $parceria->industria_local_id === $industria, 404);
        $regioes = RegioesParceria::doRequest($request);
        DB::connection(config('tenancy.database.central_connection'))->transaction(function () use ($parceria, $regioes, $request) {
            $parceria = Parceria::lockForUpdate()->findOrFail($parceria->id);
            abort_unless(in_array($parceria->status, ['pendente', 'aceita'], true), 409, 'Reenvie a solicitação para alterar uma parceria recusada ou cancelada.');
            if ($parceria->regioesInteresse() !== $regioes) {
                $parceria->update(['uf' => $regioes[0]['uf'], 'cidade' => $regioes[0]['cidade'], 'regiao' => RegioesParceria::resumo($regioes), 'regioes_anteriores' => $parceria->regioesInteresse(), 'regioes_interesse' => $regioes, 'regiao_anterior' => $parceria->regiao, 'regiao_atualizada_em' => now(), 'regiao_alterada_por' => $request->user()->id]);
            }
        });

        return back()->with('status', 'Preferência de região salva e disponível para a agência. Contratos e roteiros não foram alterados.');
    }

    public function cancelar(Request $request, Parceria $parceria, Parcerias $servico)
    {
        $industria = $servico->industriaDoUsuario($request->user());
        abort_unless($parceria->industria_tenant_id === tenant('id') && $parceria->industria_local_id === $industria, 404);
        DB::connection(config('tenancy.database.central_connection'))->transaction(function () use ($parceria) {
            $parceria = Parceria::lockForUpdate()->findOrFail($parceria->id);
            abort_unless($parceria->status === 'pendente', 409);
            $parceria->update(['status' => 'cancelada']);
        });

        return back()->with('status', 'Solicitação cancelada.');
    }

    public function perfil(Request $request, Parcerias $parcerias, PerfilAgencia $servico)
    {
        $perfil = $parcerias->agenciaDoUsuario($request->user());
        $servico->salvar($perfil, $request->only(['apresentacao', 'email_comercial', 'regioes', 'disponivel']), false);

        return back()->with('status', 'Apresentação e regiões atualizadas. A aprovação é controlada pelo super admin.');
    }
}
