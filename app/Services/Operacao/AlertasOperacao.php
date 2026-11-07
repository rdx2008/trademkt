<?php

namespace App\Services\Operacao;

use App\Enums\Perfil;
use App\Models\Agencia;
use App\Models\Alerta;
use App\Models\Demanda;
use App\Models\User;
use App\Models\Visita;

class AlertasOperacao
{
    public function __construct(private Equipe $equipe) {}

    public function emitir(string $tipo, Visita|Demanda $referencia, string $titulo, string $mensagem): void
    {
        $roteiro = $referencia instanceof Visita ? $referencia->roteiro : $referencia->visita?->roteiro;
        $promotor = $roteiro?->promotor_id;
        $agencia = Agencia::find($referencia->agencia_id);
        $vinculada = $agencia && $agencia->agencia_tenant_id !== null;

        // Pessoas desta instalação: gestão da indústria, carteira e loja. A equipe de uma
        // agência interna antiga também mora aqui.
        $locais = User::where('ativo', true)->where(function ($q) use ($referencia, $promotor, $vinculada) {
            $q->where('perfil', Perfil::AdminInstalacao->value)
                ->orWhere(fn ($g) => $g->where('perfil', Perfil::GerenteTrade->value)->where('industria_id', $referencia->industria_id))
                ->orWhere(fn ($r) => $r->where('perfil', Perfil::Representante->value)->where('industria_id', $referencia->industria_id)->whereHas('carteiraPdvs', fn ($p) => $p->whereKey($referencia->pdv_id)))
                ->orWhere(fn ($p) => $p->where('perfil', Perfil::GerentePdv->value)->whereHas('pdvsGerenciados', fn ($loja) => $loja->whereKey($referencia->pdv_id)))
                ->when(! $vinculada, fn ($q) => $q
                    ->orWhere(fn ($a) => $a->whereIn('perfil', [Perfil::AdminAgencia->value, Perfil::Supervisor->value])->where('agencia_id', $referencia->agencia_id))
                    ->when($promotor, fn ($p) => $p->orWhere('id', $promotor)));
        })->pluck('id')->map(fn ($id) => [null, (int) $id]);

        // Pessoas da agência vinculada: ids do banco dela, marcados com a agência.
        $remotos = collect();
        if ($vinculada) {
            $remotos = $this->equipe->gestores($agencia)->map(fn ($p) => [$agencia->id, $p->id]);
            if ($promotor && $this->equipe->pessoa($agencia, $promotor)?->ativo) {
                $remotos->push([$agencia->id, $promotor]);
            }
        }

        $chave = $tipo.':'.($referencia instanceof Visita ? 'visita:' : 'demanda:').$referencia->id;
        foreach ($locais->merge($remotos)->unique(fn ($d) => $d[0].':'.$d[1]) as [$agenciaId, $usuarioId]) {
            Alerta::firstOrCreate(['agencia_id' => $agenciaId, 'usuario_id' => $usuarioId, 'chave' => $chave], [
                'tipo' => $tipo, 'titulo' => $titulo, 'mensagem' => $mensagem,
                'visita_id' => $referencia instanceof Visita ? $referencia->id : null,
                'demanda_id' => $referencia instanceof Demanda ? $referencia->id : null,
            ]);
        }
    }
}
