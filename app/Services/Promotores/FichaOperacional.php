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
