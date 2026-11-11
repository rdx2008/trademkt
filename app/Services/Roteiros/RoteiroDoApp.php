<?php

namespace App\Services\Roteiros;

use App\Models\Roteiro;
use App\Models\Sku;
use App\Models\User;
use App\Models\Visita;
use App\Support\Contexto;
use Carbon\CarbonInterface;

/**
 * Roteiro do dia no formato do app do promotor, lido do banco da operação em uso.
 *
 * Quando o promotor entra pela agência, o app soma os roteiros de cada indústria atendida; por
 * isso cada visita e cada indústria levam o código da empresa (`industria`), que o app usa nas
 * rotas de execução (`/api/v1/industrias/{industria}/visitas/{visita}/...`).
 */
class RoteiroDoApp
{
    /** @return array{roteiro_id: ?int, supervisor: ?array, visitas: list<array>, industrias: list<array>} */
    public function montar(User $promotor, CarbonInterface $data): array
    {
        $roteiro = Roteiro::query()
            ->doPromotor($promotor->agencia_id, $promotor->id, $data->toDateString())
            ->with(['visitas.pdv', 'visitas.industria'])
            ->first();
        $supervisor = $roteiro?->supervisor();
        $visitas = $roteiro?->visitas ?? collect();
        $codigo = Contexto::operacao()?->codigo ?? tenant('codigo');

        $industrias = $visitas->pluck('industria')->unique('id')->values()->map(fn ($industria) => [
            'id' => $industria->id,
            'industria' => $codigo,
            'nome' => $industria->nome,
            'skus' => Sku::query()
                ->where('industria_id', $industria->id)
                ->where('ativo', true)
                ->with('embalagens')
                ->orderBy('descricao')
                ->get()
                ->map(fn (Sku $sku) => [
                    'id' => $sku->id,
                    'codigo' => $sku->codigo,
                    'descricao' => $sku->descricao,
                    'categoria' => $sku->categoria,
                    'foto_url' => $sku->foto ? Contexto::rotaApi('skus.foto', ['sku' => $sku]) : null,
                    'embalagens' => $sku->embalagensOrdenadas()->map(fn ($e) => [
                        'tipo' => $e->tipo->value,
                        'quantidade' => $e->quantidade,
                        'gtin' => $e->gtin,
                    ])->all(),
                ])->all(),
        ])->all();

        return [
            'roteiro_id' => $roteiro?->id,
            'supervisor' => $supervisor ? ['id' => $supervisor->id, 'nome' => $supervisor->nome] : null,
            'visitas' => $visitas->values()->map(fn (Visita $v, int $i) => [
                'id' => $v->id,
                'industria' => $codigo,
                'ordem' => $i + 1,
                'origem' => $v->origem->value,
                'status' => $v->status->value,
                'check_in' => $v->check_in,
                'industria_id' => $v->industria_id,
                'pdv' => [
                    'id' => $v->pdv->id,
                    'nome' => $v->pdv->nome,
                    'cnpj' => $v->pdv->cnpj,
                    'endereco' => $v->pdv->enderecoCompleto(),
                    'lat' => $v->pdv->lat,
                    'lng' => $v->pdv->lng,
                    'raio_m' => $v->pdv->raio_m,
                ],
            ])->all(),
            'industrias' => $industrias,
        ];
    }
}
