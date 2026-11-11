<?php

namespace App\Services\Promotores;

use App\Enums\StatusVisita;
use App\Models\Tenant;
use App\Services\Operacao\Equipe;
use App\Services\Rede\Operacao;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * O que o promotor fez e vai fazer, somando todas as indústrias que a agência atende. As visitas
 * moram no banco de cada indústria; aqui só se lê (conexão própria, sem trocar o tenant).
 */
class FichaOperacional
{
    public function __construct(private Operacao $operacao) {}

    /**
     * @return array{agenda: Collection, historico: Collection, metricas: array<string, mixed>}
     */
    public function de(Tenant $agencia, int $promotorId, int $diasAgenda = 7, int $diasHistorico = 30): array
    {
        $hoje = now()->toDateString();
        $fimAgenda = now()->addDays($diasAgenda - 1)->toDateString();
        $inicioHistorico = now()->subDays($diasHistorico)->toDateString();

        $agenda = collect();
        $historico = collect();

        foreach ($this->operacao->industriasDe($agencia) as $industria) {
            $conexao = Equipe::conexao($industria);
            $local = DB::connection($conexao)->table('agencias')->where('agencia_tenant_id', $agencia->id)->value('id');
            if (! $local) {
                continue;
            }
            $linhas = DB::connection($conexao)->table('visitas as v')
                ->join('roteiros as r', 'r.id', '=', 'v.roteiro_id')
                ->join('pdvs as p', 'p.id', '=', 'v.pdv_id')
                ->where('r.promotor_id', $promotorId)
                ->where('r.agencia_id', $local)
                ->whereBetween('v.data', [$inicioHistorico, $fimAgenda])
                ->orderBy('v.data')->orderBy('v.ordem')
                ->get(['v.id', 'v.data', 'v.status', 'v.tipo_conclusao', 'v.duracao_segundos', 'v.concluida_em', 'p.nome as pdv', 'p.cidade'])
                ->map(function ($l) use ($industria) {
                    $l->industria = $industria->nome ?? $industria->id;
                    $l->data = substr((string) $l->data, 0, 10);
                    $l->situacao = StatusVisita::tryFrom($l->status)?->label() ?? $l->status;

                    return $l;
                });

            $agenda = $agenda->merge($linhas->filter(fn ($l) => $l->data >= $hoje));
            $historico = $historico->merge($linhas->filter(fn ($l) => $l->data < $hoje));
        }

        $agenda = $agenda->sortBy(['data', 'pdv'])->values();
        $historico = $historico->sortByDesc('data')->values();

        return [
            'agenda' => $agenda->groupBy('data'),
            'historico' => $historico,
            'metricas' => $this->metricas($historico),
        ];
    }

    /**
     * SKUs trabalhados pelo promotor no período, uma linha por SKU de cada visita realizada, somando as
     * indústrias atendidas. Ruptura não conta como trabalhado. É o que se confere para pagar o freelancer.
     *
     * @return Collection<int, object{data: string, industria: string, visita_id: int, pdv: string, codigo: string, descricao: string, situacao: string}>
     */
    public function skusTrabalhados(Tenant $agencia, int $promotorId, string $inicio, string $fim): Collection
    {
        $linhas = collect();
        foreach ($this->operacao->industriasDe($agencia) as $industria) {
            $conexao = Equipe::conexao($industria);
            $local = DB::connection($conexao)->table('agencias')->where('agencia_tenant_id', $agencia->id)->value('id');
            if (! $local) {
                continue;
            }
            $partes = DB::connection($conexao)->table('visita_skus as vs')
                ->join('visitas as v', 'v.id', '=', 'vs.visita_id')
                ->join('roteiros as r', 'r.id', '=', 'v.roteiro_id')
                ->join('pdvs as p', 'p.id', '=', 'v.pdv_id')
                ->join('skus as s', 's.id', '=', 'vs.sku_id')
                ->where('r.promotor_id', $promotorId)->where('r.agencia_id', $local)
                ->where('v.status', StatusVisita::Realizada->value)
                ->where('vs.situacao_estoque', '!=', 'ruptura')
                ->whereBetween('v.data', [$inicio, $fim])
                ->orderBy('v.data')->orderBy('v.id')->orderBy('s.descricao')
                ->get(['v.data', 'v.id as visita_id', 'p.nome as pdv', 's.codigo', 's.descricao', 'vs.situacao_estoque as situacao'])
                ->map(function ($l) use ($industria) {
                    $l->data = substr((string) $l->data, 0, 10);
                    $l->industria = (string) ($industria->nome ?? $industria->id);

                    return $l;
                });
            $linhas = $linhas->merge($partes);
        }

        return $linhas->sortBy(['data', 'industria', 'visita_id'])->values();
    }

    /**
     * Visitas de hoje por promotor, somando as indústrias: total e quantas já foram iniciadas.
     *
     * @param  list<int>  $promotorIds
     * @return array<int, array{total: int, iniciadas: int}>
     */
    public function resumoDeHoje(Tenant $agencia, array $promotorIds): array
    {
        $resumo = [];
        if ($promotorIds === []) {
            return $resumo;
        }
        foreach ($this->operacao->industriasDe($agencia) as $industria) {
            $conexao = Equipe::conexao($industria);
            $local = DB::connection($conexao)->table('agencias')->where('agencia_tenant_id', $agencia->id)->value('id');
            if (! $local) {
                continue;
            }
            $linhas = DB::connection($conexao)->table('visitas as v')
                ->join('roteiros as r', 'r.id', '=', 'v.roteiro_id')
                ->where('r.agencia_id', $local)
                ->whereDate('v.data', now()->toDateString())
                ->whereIn('r.promotor_id', $promotorIds)
                ->groupBy('r.promotor_id')
                ->selectRaw("r.promotor_id as id, COUNT(*) as total, SUM(CASE WHEN v.status <> 'previsto' THEN 1 ELSE 0 END) as iniciadas")
                ->get();
            foreach ($linhas as $l) {
                $resumo[$l->id]['total'] = ($resumo[$l->id]['total'] ?? 0) + (int) $l->total;
                $resumo[$l->id]['iniciadas'] = ($resumo[$l->id]['iniciadas'] ?? 0) + (int) $l->iniciadas;
            }
        }

        return $resumo;
    }

    /** @param Collection<int, object> $historico */
    private function metricas(Collection $historico): array
    {
        $realizadas = $historico->filter(fn ($l) => in_array($l->status, [StatusVisita::Realizada->value, StatusVisita::EmAtencao->value], true));
        $comTempo = $realizadas->filter(fn ($l) => (int) $l->duracao_segundos > 0);

        return [
            'previstas' => $historico->count(),
            'realizadas' => $historico->where('status', StatusVisita::Realizada->value)->count(),
            'nao_executadas' => $historico->where('status', StatusVisita::NaoExecutada->value)->count(),
            'impedimentos' => $historico->where('tipo_conclusao', 'impedimento')->count(),
            'rupturas' => $historico->where('tipo_conclusao', 'ruptura')->count(),
            'tempo_medio_min' => $comTempo->isEmpty() ? null : (int) round($comTempo->avg('duracao_segundos') / 60),
        ];
    }
}
