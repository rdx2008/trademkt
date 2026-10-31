<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\Perfil;
use App\Enums\StatusVisita;
use App\Enums\TipoInstalacao;
use App\Http\Controllers\Controller;
use App\Models\ContratoVisita;
use App\Models\Rede\Parceria;
use App\Services\Painel\ResumoOperacional;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, ResumoOperacional $resumo): View
    {
        $usuario = $request->user();

        $dados = $request->validate([
            'data' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', Rule::enum(StatusVisita::class)],
        ]);
        $data = isset($dados['data']) ? Carbon::parse($dados['data']) : today();
        [$titulo, $descricao] = match ($usuario->perfil) {
            Perfil::AdminInstalacao => ['Visão da operação', 'Cadastros, equipes e visitas da sua instalação em um só lugar.'],
            Perfil::AdminAgencia => ['Gestão da agência', 'Organize sua equipe e acompanhe os atendimentos das suas indústrias.'],
            Perfil::GerenteTrade => ['Resultados da indústria', 'Acompanhe a execução da sua marca e as agências prestadoras.'],
            Perfil::Supervisor => ['Central do supervisor', 'Distribua as visitas e acompanhe o dia da sua equipe.'],
            Perfil::Representante => ['Minha carteira', 'Acompanhe as visitas da sua indústria nos PDVs da sua carteira.'],
            Perfil::Promotor => ['Minha agenda', 'Consulte as lojas e os atendimentos atribuídos a você.'],
            Perfil::GerentePdv => ['Minhas lojas', 'Veja as marcas, agências e visitas das lojas vinculadas a você.'],
        };

        return view('tenant.dashboard', [
            'usuario' => $usuario,
            'cliente' => tenant(),
            'titulo' => $titulo,
            'descricao' => $descricao,
            'data' => $data,
            'filtroStatus' => $dados['status'] ?? '',
            'precisaAgencia' => tenant()->tipo === TipoInstalacao::Industria && $usuario->temPerfil(Perfil::AdminInstalacao, Perfil::GerenteTrade)
                && ! Parceria::where('industria_tenant_id', tenant('id'))->where('industria_local_id', $usuario->industria_id)->where('status', 'aceita')->exists()
                && ! ContratoVisita::where('industria_id', $usuario->industria_id)->exists(),
            'resumo' => $resumo->gerar($usuario, $data, $dados['status'] ?? null),
        ]);
    }
}
