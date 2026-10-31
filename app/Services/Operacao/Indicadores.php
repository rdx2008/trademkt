<?php

namespace App\Services\Operacao;

use App\Models\Agencia;
use App\Models\Demanda;
use App\Models\Industria;
use App\Models\Rede;
use App\Models\Regiao;
use App\Models\Roteiro;
use App\Models\User;
use App\Models\Visita;
use App\Models\VisitaSku;
use App\Services\Painel\ResumoOperacional;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class Indicadores
{
    public function opcoes(User $u): array
    {
        $escopo = app(ResumoOperacional::class);
        $v = $escopo->visitas($u);
        $c = $escopo->contratos($u);
        $pdvs = $escopo->pdvs($u)->get(['pdvs.id', 'pdvs.nome', 'pdvs.rede_id', 'pdvs.regiao_id']);
        $roteiros = Roteiro::whereIn('id', (clone $v)->select('roteiro_id'))->get(['promotor_id', 'supervisor_id']);

        return [
            'industria_id' => Industria::whereIn('id', (clone $v)->distinct()->pluck('industria_id')->merge((clone $c)->distinct()->pluck('industria_id'))->unique())->orderBy('nome')->pluck('nome', 'id'),
            'agencia_id' => Agencia::whereIn('id', (clone $v)->distinct()->pluck('agencia_id')->merge((clone $c)->distinct()->pluck('agencia_id'))->unique())->orderBy('nome')->pluck('nome', 'id'),
            'pdv_id' => $pdvs->sortBy('nome')->pluck('nome', 'id'),
            'regiao_id' => Regiao::whereIn('id', $pdvs->pluck('regiao_id')->filter())->orderBy('nome')->pluck('nome', 'id'),
            'rede_id' => Rede::whereIn('id', $pdvs->pluck('rede_id')->filter())->orderBy('nome')->pluck('nome', 'id'),
            'promotor_id' => User::whereIn('id', $roteiros->pluck('promotor_id')->filter())->orderBy('nome')->pluck('nome', 'id'),
            'supervisor_id' => User::whereIn('id', $roteiros->pluck('supervisor_id')->filter())->orderBy('nome')->pluck('nome', 'id'),
        ];
    }

    public function filtros(Request $r, array $opcoes): array
    {
        $entrada = $r->only(array_keys($opcoes));
        $entrada['inicio'] = $r->input('inicio') ?: now()->startOfMonth()->toDateString();
        $entrada['fim'] = $r->input('fim') ?: today()->toDateString();
        $regras = ['inicio' => ['required', 'date_format:Y-m-d'], 'fim' => ['required', 'date_format:Y-m-d', 'after_or_equal:inicio']];
        foreach ($opcoes as $campo => $valores) {
            $regras[$campo] = ['nullable', 'integer', Rule::in($valores->keys()->all())];
        }
        $dados = Validator::make($entrada, $regras)->validate();
        if (Carbon::parse($dados['inicio'])->diffInDays(Carbon::parse($dados['fim'])) > 365) {
            throw ValidationException::withMessages(['fim' => 'Consulte até 366 dias por vez.']);
        }

        return $dados;
    }

    public function visitas(User $u, array $f)
    {
        return $this->aplicar(app(ResumoOperacional::class)->visitas($u), $f);
    }

    private function aplicar($q, array $f, bool $demanda = false)
    {
        foreach (['industria_id', 'agencia_id', 'pdv_id'] as $campo) {
            if (! empty($f[$campo])) {
                $q->where($campo, $f[$campo]);
            }
        }
        foreach (['rede_id', 'regiao_id'] as $campo) {
            if (! empty($f[$campo])) {
                $q->whereHas('pdv', fn ($p) => $p->where($campo, $f[$campo]));
            }
        }
        foreach (['promotor_id', 'supervisor_id'] as $campo) {
            if (! empty($f[$campo])) {
                $q->whereHas($demanda ? 'visita.roteiro' : 'roteiro', fn ($r) => $r->where($campo, $f[$campo]));
            }
        }

        return $q;
    }

    public function demandas(User $u, array $f)
    {
        return $this->aplicar(Demanda::visiveisPara($u), $f, true)->whereDate('created_at', '>=', $f['inicio'])->whereDate('created_at', '<=', $f['fim']);
    }

    public function execucoes(User $u, array $f)
    {
        return $this->visitas($u, $f)->whereDate('concluida_em', '>=', $f['inicio'])->whereDate('concluida_em', '<=', $f['fim']);
    }

    public function producao(User $u, array $f)
    {
        return VisitaSku::whereIn('visita_id', $this->execucoes($u, $f)->where('status', 'realizada')->select('visitas.id'))
            ->where('situacao_estoque', '!=', 'ruptura')->join('visitas', 'visitas.id', '=', 'visita_skus.visita_id')->join('roteiros', 'roteiros.id', '=', 'visitas.roteiro_id')->join('users', 'users.id', '=', 'roteiros.promotor_id')
            ->selectRaw('DATE(visitas.concluida_em) as dia, users.id as promotor_id, users.nome as promotor, COUNT(*) as total')
            ->groupByRaw('DATE(visitas.concluida_em), users.id, users.nome')->orderByDesc('dia')->orderBy('promotor');
    }

    public function rupturas(User $u, array $f)
    {
        return VisitaSku::whereIn('visita_id', $this->execucoes($u, $f)->select('visitas.id'))->where('situacao_estoque', 'ruptura')
            ->join('visitas', 'visitas.id', '=', 'visita_skus.visita_id')->join('pdvs', 'pdvs.id', '=', 'visitas.pdv_id')->join('skus', 'skus.id', '=', 'visita_skus.sku_id')
            ->selectRaw('skus.id as sku_id, skus.codigo, skus.descricao, pdvs.id as pdv_id, pdvs.nome as pdv, COUNT(*) as total')
            ->groupBy('skus.id', 'skus.codigo', 'skus.descricao', 'pdvs.id', 'pdvs.nome')->orderByDesc('total')->orderBy('skus.id');
    }

    public function resumo(User $u, array $f): array
    {
        $agenda = $this->visitas($u, $f)->whereDate('data', '>=', $f['inicio'])->whereDate('data', '<=', $f['fim']);
        $contrato = $this->visitas($u, $f)->where('origem', 'contrato')->whereDate('data_prevista', '>=', $f['inicio'])->whereDate('data_prevista', '<=', $f['fim']);
        $previstas = (clone $contrato)->count();
        $cobertas = (clone $contrato)->where('status', 'realizada')->count();
        $execucoes = $this->execucoes($u, $f);
        $d = $this->demandas($u, $f);
        $noPrazo = (clone $d)->where('status', 'atendida')->whereColumn('atendida_em', '<=', 'prazo_em')->count();
        $atrasadas = (clone $d)->where('status', '!=', 'recusada')->where(fn ($q) => $q->whereColumn('atendida_em', '>', 'prazo_em')->orWhere(fn ($p) => $p->whereNull('atendida_em')->where('prazo_em', '<', now())))->count();

        return [
            'previstas' => $previstas, 'cobertas' => $cobertas, 'cobertura' => $previstas ? round($cobertas / $previstas * 100, 1) : null,
            'visitas' => (clone $agenda)->count(), 'realizadas' => (clone $execucoes)->where('status', 'realizada')->count(),
            'nao_executadas' => (clone $agenda)->where('status', 'nao_executada')->count(),
            'impedimentos' => (clone $execucoes)->where('tipo_conclusao', 'impedimento')->count(),
            'tempo_medio_min' => round(((clone $execucoes)->where('status', 'realizada')->avg('duracao_segundos') ?? 0) / 60, 1),
            'skus_trabalhados' => VisitaSku::whereIn('visita_id', $this->execucoes($u, $f)->where('status', 'realizada')->select('id'))->where('situacao_estoque', '!=', 'ruptura')->count(),
            'rupturas' => VisitaSku::whereIn('visita_id', $this->execucoes($u, $f)->select('id'))->where('situacao_estoque', 'ruptura')->count(),
            'demandas' => (clone $d)->count(), 'demandas_no_prazo' => $noPrazo, 'demandas_atrasadas' => $atrasadas,
            'relatos_ruptura' => (clone $d)->where('tipo', 'ruptura')->count(),
        ];
    }

    public function tempos(User $u, array $f)
    {
        return Visita::whereIn('visitas.id', $this->execucoes($u, $f)->select('visitas.id'))->join('pdvs', 'pdvs.id', '=', 'visitas.pdv_id')
            ->join('roteiros', 'roteiros.id', '=', 'visitas.roteiro_id')->join('users', 'users.id', '=', 'roteiros.promotor_id')
            ->selectRaw("pdvs.id as pdv_id, pdvs.nome as pdv, users.id as promotor_id, users.nome as promotor, COUNT(*) as total, SUM(CASE WHEN visitas.tipo_conclusao = 'impedimento' THEN 1 ELSE 0 END) as impedimentos, AVG(CASE WHEN visitas.status = 'realizada' THEN visitas.duracao_segundos ELSE NULL END) / 60 as minutos")
            ->groupBy('pdvs.id', 'pdvs.nome', 'users.id', 'users.nome')->orderBy('pdv')->orderBy('promotor');
    }
}
