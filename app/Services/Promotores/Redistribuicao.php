<?php

namespace App\Services\Promotores;

use App\Models\Roteiro;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Visita;
use App\Services\Operacao\Equipe;
use App\Services\Rede\Operacao;
use App\Services\Roteiros\Atribuicao;
use App\Support\Pessoa;
use Illuminate\Validation\ValidationException;

/**
 * Passa as visitas já atribuídas a um promotor (afastado ou desativado) para colegas da mesma
 * agência, em todas as indústrias atendidas. Para cada visita escolhe, entre quem está ativo,
 * habilitado na indústria e livre de afastamento no dia, primeiro quem tem a cidade base do PDV e,
 * dentro do grupo, quem tem menos visitas naquele dia. Sem substituto, a visita fica como está.
 *
 * As visitas moram no banco de cada indústria: a operação é aberta como no app (abrir/fechar).
 */
class Redistribuicao
{
    public function __construct(private Operacao $operacao, private Atribuicao $atribuicao) {}

    /**
     * @return list<array{industria: string, visita_id: int, data: string, pdv: string, cidade: ?string, novo_id: ?int, novo_nome: ?string, movida: bool, erro: ?string}>
     */
    public function executar(Tenant $agencia, User $promotor, string $inicio, string $fim, User $eu, bool $aplicar = false): array
    {
        $inicio = max($inicio, today()->toDateString());
        $linhas = [];

        foreach ($this->operacao->industriasDe($agencia) as $industria) {
            Equipe::esquecer();
            $local = $this->operacao->abrir($agencia, $industria, $eu);
            if (! $local) {
                continue;
            }
            try {
                array_push($linhas, ...$this->daIndustria($industria, $local, $promotor, $inicio, $fim, $eu, $aplicar));
            } finally {
                $this->operacao->fechar($eu);
                Equipe::esquecer();
            }
        }

        return $linhas;
    }

    private function daIndustria(Tenant $industria, $local, User $promotor, string $inicio, string $fim, User $eu, bool $aplicar): array
    {
        $visitas = Visita::with('pdv')
            ->where('status', 'previsto')
            ->whereDate('data', '>=', $inicio)->whereDate('data', '<=', $fim)
            ->whereHas('roteiro', fn ($q) => $q->where('promotor_id', $promotor->id)->where('agencia_id', $local->id))
            ->orderBy('data')->orderBy('ordem')->get();
        if ($visitas->isEmpty()) {
            return [];
        }

        $equipe = app(Equipe::class);
        // Carga atual por colega e dia, para repartir sem concentrar.
        $carga = [];
        foreach (Roteiro::where('agencia_id', $local->id)->whereDate('data', '>=', $inicio)->whereDate('data', '<=', $fim)->withCount('visitas')->get() as $r) {
            $carga[$r->promotor_id][$r->data->toDateString()] = $r->visitas_count;
        }

        $linhas = [];
        foreach ($visitas as $visita) {
            $dia = $visita->data->toDateString();
            $cidade = mb_strtolower(trim((string) $visita->pdv?->cidade));
            $substituto = $this->escolher($equipe, $local, $promotor->id, $dia, $cidade, $carga);

            $linha = [
                'industria' => (string) ($industria->nome ?? $industria->id),
                'visita_id' => $visita->id,
                'data' => $dia,
                'pdv' => (string) $visita->pdv?->nome,
                'cidade' => $visita->pdv?->cidade,
                'novo_id' => $substituto?->id,
                'novo_nome' => $substituto?->nome,
                'movida' => false,
                'erro' => null,
            ];
            if ($substituto) {
                $carga[$substituto->id][$dia] = ($carga[$substituto->id][$dia] ?? 0) + 1;
                if ($aplicar) {
                    try {
                        $this->atribuicao->mover($visita, $substituto, $visita->data, $eu);
                        $linha['movida'] = true;
                    } catch (ValidationException $e) {
                        $linha['erro'] = collect($e->errors())->flatten()->first();
                    }
                }
            }
            $linhas[] = $linha;
        }

        return $linhas;
    }

    /** @param  array<int, array<string, int>>  $carga */
    private function escolher(Equipe $equipe, $local, int $deQuem, string $dia, string $cidade, array $carga): ?Pessoa
    {
        $candidatos = $equipe->promotores($local)
            ->filter(fn (Pessoa $p) => $p->id !== $deQuem && $equipe->promotorAtivo($local, $p->id, $dia) !== null);
        if ($candidatos->isEmpty()) {
            return null;
        }
        $daCidade = $cidade === '' ? collect() : $candidatos->filter(fn (Pessoa $p) => mb_strtolower((string) $p->cidadeBase) === $cidade);
        $grupo = $daCidade->isNotEmpty() ? $daCidade : $candidatos;

        return $grupo->sortBy(fn (Pessoa $p) => [$carga[$p->id][$dia] ?? 0, mb_strtolower($p->nome)])->first();
    }
}
