<?php

namespace App\Http\Controllers\Central;

use App\Enums\TipoInstalacao;
use App\Http\Controllers\Controller;
use App\Models\ConsentimentoPromotor;
use App\Models\PromotorGlobal;
use App\Models\Tenant;
use App\Support\Estados;
use App\Support\TermoDaRede;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Rede de promotores (captação): quantas pessoas únicas há na plataforma, por UF e cidade, e em quais
 * agências. Só números: nenhum dado pessoal sai daqui. "Ativos" são quem tem cadastro ativo em ao
 * menos uma agência; "com aceite" são quem aceitou o termo da rede e não revogou.
 */
class RedePromotoresController extends Controller
{
    public function index(Request $request): View
    {
        $uf = strtoupper((string) $request->query('uf'));
        $uf = in_array($uf, Estados::UFS, true) ? $uf : null;

        $ativo = 'exists (select 1 from promotor_agencias pa where pa.promotor_global_codigo = promotores_globais.codigo and pa.ativo = 1)';
        $aceite = "exists (select 1 from consentimentos_promotor c where c.promotor_global_codigo = promotores_globais.codigo and c.finalidade = '".TermoDaRede::FINALIDADE."' and c.revogado_em is null)";
        $soma = "count(*) as total, sum(case when {$ativo} then 1 else 0 end) as ativos, sum(case when {$aceite} then 1 else 0 end) as com_aceite";

        $totais = PromotorGlobal::query()->selectRaw($soma)->first();

        $porUf = PromotorGlobal::query()->selectRaw("coalesce(uf_base, '') as uf, {$soma}")
            ->groupBy('uf')->orderByDesc('ativos')->orderBy('uf')->get();

        $porCidade = PromotorGlobal::query()->selectRaw("municipio_ibge, coalesce(cidade_base, '') as cidade, coalesce(uf_base, '') as uf, {$soma}")
            ->when($uf, fn ($q) => $q->where('uf_base', $uf))
            ->groupBy('municipio_ibge', 'cidade', 'uf')->orderByDesc('ativos')->orderBy('cidade')->limit(200)->get();

        $conectado = DB::connection(config('tenancy.database.central_connection'));
        $ativosPorAgencia = $conectado->table('promotor_agencias')->where('ativo', true)->selectRaw('tenant_id, count(*) as n')->groupBy('tenant_id')->pluck('n', 'tenant_id');
        $aceitesPorAgencia = ConsentimentoPromotor::query()->where('finalidade', TermoDaRede::FINALIDADE)->whereNull('revogado_em')
            ->selectRaw('tenant_id, count(distinct promotor_global_codigo) as n')->groupBy('tenant_id')->pluck('n', 'tenant_id');
        $agencias = Tenant::where('tipo', TipoInstalacao::Agencia->value)->orderBy('nome')->get()
            ->map(fn (Tenant $t) => [
                'id' => $t->id,
                'nome' => $t->nome,
                'habilitada' => $t->redeJobsHabilitada(),
                'ativos' => (int) ($ativosPorAgencia[$t->id] ?? 0),
                'com_aceite' => (int) ($aceitesPorAgencia[$t->id] ?? 0),
            ]);

        return view('central.rede.index', [
            'totais' => $totais,
            'semCidade' => PromotorGlobal::whereNull('municipio_ibge')->count(),
            'porUf' => $porUf,
            'porCidade' => $porCidade,
            'agencias' => $agencias,
            'uf' => $uf,
            'ufs' => Estados::UFS,
        ]);
    }
}
