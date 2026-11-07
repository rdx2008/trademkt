<?php

namespace App\Services\Painel;

use App\Enums\Perfil;
use App\Enums\StatusVisita;
use App\Models\ContratoVisita;
use App\Models\Pdv;
use App\Models\Sku;
use App\Models\User;
use App\Models\Visita;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class ResumoOperacional
{
    public function visitas(User $usuario): Builder
    {
        if ($usuario->perfil === Perfil::GerentePdv) {
            return Visita::whereIn('pdv_id', $usuario->pdvsGerenciados()->select('pdvs.id'));
        }

        return Visita::visiveisPara($usuario)
            ->when($usuario->perfil === Perfil::Representante, fn ($q) => $q
                ->whereIn('pdv_id', $usuario->carteiraPdvs()->select('pdvs.id')));
    }

    public function contratos(User $usuario): Builder
    {
        if ($usuario->perfil === Perfil::GerentePdv) {
            return ContratoVisita::whereIn('pdv_id', $usuario->pdvsGerenciados()->select('pdvs.id'));
        }

        return ContratoVisita::visiveisPara($usuario)
            ->when($usuario->perfil === Perfil::Representante, fn ($q) => $q
                ->whereIn('pdv_id', $usuario->carteiraPdvs()->select('pdvs.id')));
    }

    public function pdvs(User $usuario): Builder
    {
        return match ($usuario->perfil) {
            Perfil::AdminInstalacao => Pdv::query(),
            Perfil::GerentePdv => $usuario->pdvsGerenciados()->getQuery(),
            Perfil::Promotor => Pdv::whereIn('id', $this->visitas($usuario)->select('pdv_id')),
            Perfil::Representante => Pdv::whereIn('id', $usuario->carteiraPdvs()->select('pdvs.id'))
                ->whereIn('id', $this->contratos($usuario)->select('pdv_id')),
            default => Pdv::whereIn('id', $this->contratos($usuario)->select('pdv_id')),
        };
    }

    public function gerar(User $usuario, Carbon $data, ?string $status): array
    {
        $base = $this->visitas($usuario);
        $dia = (clone $base)->whereDate('data', $data);
        $contagens = (clone $dia)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $total = (int) $contagens->sum();
        $realizadas = (int) ($contagens[StatusVisita::Realizada->value] ?? 0);
        $inicio = $data->copy()->subDays(6);
        $porDia = (clone $base)->whereDate('data', '>=', $inicio->toDateString())->whereDate('data', '<=', $data->toDateString())
            ->selectRaw('DATE(data) as dia, COUNT(*) as total')->groupByRaw('DATE(data)')->pluck('total', 'dia');
        $semana = collect(range(0, 6))->map(fn ($i) => [
            'dia' => $inicio->copy()->addDays($i)->format('d/m'),
            'total' => (int) ($porDia[$inicio->copy()->addDays($i)->toDateString()] ?? 0),
        ]);
        $estados = collect(StatusVisita::cases())->map(fn ($estado) => [
            'valor' => $estado->value,
            'rotulo' => $estado->label(),
            'total' => (int) ($contagens[$estado->value] ?? 0),
        ]);
        $pdvs = $this->pdvs($usuario);
        $contratos = $this->contratos($usuario);
        $podeGerenciar = $usuario->temPerfil(Perfil::AdminInstalacao, Perfil::AdminAgencia, Perfil::GerenteTrade, Perfil::Supervisor);
        $skus = Sku::query()->where('ativo', true);
        if ($usuario->perfil !== Perfil::AdminInstalacao) {
            $skus->whereIn('industria_id', (clone $contratos)->select('industria_id'));
        }
        $lojas = $usuario->perfil === Perfil::GerentePdv
            ? (clone $pdvs)->with(['contratos' => fn ($q) => $q->vigentesEm($data)->with(['industria', 'agencia'])])->orderBy('nome')->paginate(6, ['pdvs.*'], 'lojas')
            : collect();
        foreach ($lojas as $loja) {
            foreach ($loja->contratos as $contrato) {
                $base = $this->visitas($usuario)->where('pdv_id', $loja->id)->where('industria_id', $contrato->industria_id)->where('agencia_id', $contrato->agencia_id);
                $contrato->setRelation('ultimaVisita', (clone $base)->where('status', 'realizada')->with('fotos')->orderByDesc('concluida_em')->orderByDesc('id')->first());
                $contrato->setRelation('proximaVisita', (clone $base)->whereIn('status', ['previsto', 'em_andamento'])->whereDate('data', '>=', today())->with(['roteiro', 'agencia'])->orderBy('data')->orderBy('ordem')->first());
            }
        }

        return [
            'total' => $total,
            'realizadas' => $realizadas,
            'percentual' => $total ? (int) round($realizadas / $total * 100) : 0,
            'estados' => $estados,
            'semana' => $semana,
            'emAndamento' => (int) ($contagens['em_andamento'] ?? 0),
            'emAtencao' => (int) ($contagens['em_atencao'] ?? 0),
            'semPromotor' => (clone $dia)->whereNull('roteiro_id')->count(),
            'pendentesAnteriores' => (clone $base)->whereDate('data', '>=', $inicio->toDateString())->whereDate('data', '<', $data->toDateString())->where('status', 'previsto')->count(),
            'cadastros' => [
                'PDVs visíveis' => (clone $pdvs)->count(),
                'Contratos vigentes' => (clone $contratos)->vigentesEm($data)->count(),
                'SKUs ativos' => $skus->count(),
                'Equipe visível' => $podeGerenciar ? User::visiveisPara($usuario)->where('ativo', true)->count() : null,
            ],
            'visitas' => (clone $dia)->when($status, fn ($q) => $q->where('status', $status))
                ->with(['pdv', 'industria', 'agencia', 'roteiro'])
                ->orderBy('ordem')->orderBy('id')->paginate(12)->withQueryString(),
            'lojas' => $lojas,
        ];
    }
}
