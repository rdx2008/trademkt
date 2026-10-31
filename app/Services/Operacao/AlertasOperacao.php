<?php

namespace App\Services\Operacao;

use App\Enums\Perfil;
use App\Models\Alerta;
use App\Models\Demanda;
use App\Models\User;
use App\Models\Visita;

class AlertasOperacao
{
    public function emitir(string $tipo, Visita|Demanda $referencia, string $titulo, string $mensagem): void
    {
        $promotor = $referencia instanceof Visita ? $referencia->roteiro?->promotor_id : $referencia->visita?->roteiro?->promotor_id;
        $usuarios = User::where('ativo', true)->where(function ($q) use ($referencia, $promotor) {
            $q->where('perfil', Perfil::AdminInstalacao->value)
                ->orWhere(fn ($g) => $g->where('perfil', Perfil::GerenteTrade->value)->where('industria_id', $referencia->industria_id))
                ->orWhere(fn ($a) => $a->whereIn('perfil', [Perfil::AdminAgencia->value, Perfil::Supervisor->value])->where('agencia_id', $referencia->agencia_id))
                ->orWhere(fn ($r) => $r->where('perfil', Perfil::Representante->value)->where('industria_id', $referencia->industria_id)->whereHas('carteiraPdvs', fn ($p) => $p->whereKey($referencia->pdv_id)))
                ->orWhere(fn ($p) => $p->where('perfil', Perfil::GerentePdv->value)->whereHas('pdvsGerenciados', fn ($loja) => $loja->whereKey($referencia->pdv_id)))
                ->when($promotor, fn ($p) => $p->orWhere('id', $promotor));
        })->pluck('id');
        foreach ($usuarios as $id) {
            Alerta::firstOrCreate(['usuario_id' => $id, 'chave' => $tipo.':'.($referencia instanceof Visita ? 'visita:' : 'demanda:').$referencia->id], [
                'tipo' => $tipo, 'titulo' => $titulo, 'mensagem' => $mensagem,
                'visita_id' => $referencia instanceof Visita ? $referencia->id : null,
                'demanda_id' => $referencia instanceof Demanda ? $referencia->id : null,
            ]);
        }
    }
}
