<?php

namespace App\Services\Roteiros;

use App\Models\Roteiro;
use App\Models\User;
use App\Models\Visita;
use App\Services\Operacao\Equipe;
use App\Support\Pessoa;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Atribui (ou desatribui) uma visita a um promotor num dia e ajusta a ordem do roteiro.
 * O promotor é da agência responsável pela visita (equipe lida pelo {@see Equipe}).
 */
class Atribuicao
{
    public function __construct(private Equipe $equipe) {}

    /**
     * @param  Pessoa|int|null  $promotor  id do promotor na agência da visita; null = volta para "sem promotor"
     * @param  User|Pessoa|int|null  $responsavel  quem atribuiu (vira o supervisor do roteiro); null = automático
     * @param  list<int>|null  $ordem  ids das visitas da célula de destino, na ordem desejada
     *
     * @throws ValidationException
     */
    public function mover(Visita $visita, Pessoa|int|null $promotor, CarbonInterface $data, User|Pessoa|int|null $responsavel = null, ?array $ordem = null): void
    {
        if (! $visita->podeSerMovida()) {
            throw ValidationException::withMessages(['visita' => 'Só visitas previstas, de hoje em diante, podem ser movidas.']);
        }
        if ($data->copy()->startOfDay()->isBefore(today())) {
            throw ValidationException::withMessages(['data' => 'Não dá para mover uma visita para um dia que já passou.']);
        }

        $promotorId = $promotor instanceof Pessoa ? $promotor->id : $promotor;
        if ($promotorId !== null && ! $this->equipe->promotorAtivo($visita->agencia_id, $promotorId)) {
            throw ValidationException::withMessages(['promotor_id' => 'Escolha um promotor ativo da agência responsável pela visita.']);
        }
        $responsavelId = $responsavel instanceof User || $responsavel instanceof Pessoa ? $responsavel->id : $responsavel;

        DB::transaction(function () use ($visita, $promotorId, $data, $responsavelId, $ordem) {
            $roteiroAntigo = $visita->roteiro;
            $roteiro = $promotorId
                ? (Roteiro::doPromotor($visita->agencia_id, $promotorId, $data->toDateString())->first()
                    ?? Roteiro::create(['promotor_id' => $promotorId, 'agencia_id' => $visita->agencia_id, 'data' => $data->toDateString(), 'supervisor_id' => $responsavelId]))
                : null;
            if ($roteiro && $responsavelId && $roteiro->supervisor_id !== $responsavelId) {
                $roteiro->update(['supervisor_id' => $responsavelId]);
            }

            $mudouDeCelula = $roteiro?->id !== $visita->roteiro_id || ! $visita->data->isSameDay($data);
            $visita->roteiro_id = $roteiro?->id;
            $visita->data = $data->toDateString();
            if ($mudouDeCelula) {
                $visita->ordem = $this->proximaOrdem($visita);
            }
            $visita->save();

            if ($ordem !== null) {
                $this->reordenar($visita, $ordem);
            }

            // Roteiro que ficou sem visitas não serve para nada.
            if ($roteiroAntigo && $roteiroAntigo->id !== $roteiro?->id && ! $roteiroAntigo->visitas()->exists()) {
                $roteiroAntigo->delete();
            }
        });
    }

    /** Visitas da mesma célula (mesmo roteiro ou "sem promotor" da mesma agência no mesmo dia). */
    private function celula(Visita $visita)
    {
        return Visita::query()
            ->whereDate('data', $visita->data)
            ->when(
                $visita->roteiro_id,
                fn ($q) => $q->where('roteiro_id', $visita->roteiro_id),
                fn ($q) => $q->whereNull('roteiro_id')->where('agencia_id', $visita->agencia_id)
            );
    }

    private function proximaOrdem(Visita $visita): int
    {
        return (int) $this->celula($visita)->whereKeyNot($visita->id)->max('ordem') + 1;
    }

    /** @param  list<int>  $ordem */
    private function reordenar(Visita $visita, array $ordem): void
    {
        $ids = $this->celula($visita)->pluck('id')->all();
        $ordem = array_values(array_unique(array_map('intval', $ordem)));
        if (array_diff($ordem, $ids) !== [] || ! in_array($visita->id, $ordem, true)) {
            throw ValidationException::withMessages(['ordem' => 'A ordem enviada não corresponde às visitas do dia.']);
        }

        // As que não vieram na lista ficam no fim, na ordem atual.
        $restantes = $this->celula($visita)->whereNotIn('id', $ordem)->orderBy('ordem')->orderBy('id')->pluck('id')->all();
        foreach ([...$ordem, ...$restantes] as $posicao => $id) {
            Visita::whereKey($id)->update(['ordem' => $posicao + 1]);
        }
    }
}
