<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\Perfil;
use App\Http\Controllers\Controller;
use App\Models\Agencia;
use App\Models\User;
use App\Models\Visita;
use App\Services\Roteiros\Atribuicao;
use App\Services\Roteiros\GeradorVisitas;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Roteiros da semana: visitas por promotor e por dia. A agência atribui e reordena;
 * a indústria e o admin da instalação só acompanham.
 */
class RoteiroController extends Controller
{
    public function semana(Request $request): View
    {
        $eu = $request->user();
        $inicio = $this->inicioDaSemana($request->input('semana'));
        $dias = collect(CarbonPeriod::create($inicio, $inicio->addDays(6)))->map(fn ($d) => CarbonImmutable::parse($d));

        // Admin da instalação acompanha uma agência por vez.
        $agencias = $eu->perfil === Perfil::AdminInstalacao ? Agencia::orderBy('nome')->pluck('nome', 'id') : collect();
        $agenciaId = match (true) {
            $eu->perfil->exigeAgencia() => $eu->agencia_id,
            $eu->perfil === Perfil::AdminInstalacao => $request->integer('agencia_id') ?: $agencias->keys()->first(),
            default => $request->integer('agencia_id') ?: null,
        };

        $visitas = Visita::query()
            ->visiveisPara($eu)
            ->when($agenciaId, fn ($q) => $q->where('agencia_id', $agenciaId))
            ->whereDate('data', '>=', $inicio->toDateString())
            ->whereDate('data', '<=', $inicio->addDays(6)->toDateString())
            ->with(['pdv', 'industria', 'roteiro'])
            ->orderBy('ordem')
            ->orderBy('id')
            ->get();

        $podeEditar = Visita::podeAtribuir($eu);

        // Linhas: promotores ativos da agência (para a agência poder atribuir) + quem já tem visita.
        $promotores = User::query()
            ->where('perfil', Perfil::Promotor->value)
            ->where(fn ($q) => $q
                ->when($podeEditar, fn ($q) => $q->orWhere(fn ($a) => $a->where('agencia_id', $agenciaId)->where('ativo', true)))
                ->orWhereIn('id', $visitas->pluck('roteiro.promotor_id')->filter()->unique()))
            ->orderBy('nome')
            ->get(['id', 'nome', 'agencia_id', 'ativo']);

        $grade = $visitas->groupBy(fn (Visita $v) => ($v->roteiro?->promotor_id ?? 0).'|'.$v->data->toDateString());

        return view('tenant.roteiros.semana', [
            'eu' => $eu,
            'inicio' => $inicio,
            'dias' => $dias,
            'promotores' => $promotores,
            'grade' => $grade,
            'semVisitas' => $visitas->isEmpty(),
            'podeEditar' => $podeEditar,
            'agencias' => $agencias,
            'agenciaId' => $agenciaId,
            'mapa' => $this->mapaDoDia($request, $visitas, $dias, $promotores),
        ]);
    }

    /** Move uma visita para outro promotor e/ou dia e define a ordem da célula de destino. */
    public function mover(Request $request, Visita $visita, Atribuicao $atribuicao): JsonResponse|RedirectResponse
    {
        $eu = $request->user();
        abort_unless(Visita::podeAtribuir($eu) && $visita->agencia_id === $eu->agencia_id, 403);

        $dados = $request->validate([
            'promotor_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'data' => ['required', 'date_format:Y-m-d'],
            'ordem' => ['nullable', 'array'],
            'ordem.*' => ['integer'],
        ]);

        $promotor = isset($dados['promotor_id']) ? User::find($dados['promotor_id']) : null;

        $atribuicao->mover($visita, $promotor, Carbon::parse($dados['data']), $eu, $dados['ordem'] ?? null);

        if ($request->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('status', $promotor
            ? "Visita a {$visita->pdv->nome} atribuída a {$promotor->nome} em {$visita->data->format('d/m')}."
            : "Visita a {$visita->pdv->nome} voltou para \"sem promotor\".");
    }

    /** Gera (ou atualiza) as visitas previstas da semana a partir dos contratos da agência. */
    public function gerar(Request $request, GeradorVisitas $gerador): RedirectResponse
    {
        $eu = $request->user();
        abort_unless(Visita::podeAtribuir($eu), 403);

        $inicio = $this->inicioDaSemana($request->input('semana'));
        $resultado = $gerador->gerar($inicio, $inicio->addDays(6), $eu->agencia_id);

        return redirect()
            ->route('roteiros.semana', ['semana' => $inicio->toDateString()])
            ->with('status', "Visitas da semana atualizadas: {$resultado['criadas']} nova(s), {$resultado['atribuidas']} já com o promotor da semana anterior, {$resultado['removidas']} removida(s).");
    }

    private function inicioDaSemana(?string $semana): CarbonImmutable
    {
        try {
            $data = $semana ? CarbonImmutable::createFromFormat('Y-m-d', $semana) : null;
        } catch (\Throwable) {
            $data = null;
        }

        return ($data ?: CarbonImmutable::today())->startOfWeek(CarbonImmutable::MONDAY);
    }

    /**
     * Pontos do mapa do dia (visitas de um promotor, ou as sem promotor, na ordem).
     *
     * @return array{dia: string, promotor: int, pontos: list<array>}
     */
    private function mapaDoDia(Request $request, Collection $visitas, Collection $dias, Collection $promotores): array
    {
        $dia = $request->input('dia');
        $dia = $dias->first(fn ($d) => $d->toDateString() === $dia)
            ?? $dias->first(fn ($d) => $d->isToday())
            ?? $dias->first();

        $promotor = $request->integer('promotor');
        if (! $request->has('promotor')) {
            $promotor = (int) ($promotores->first()?->id ?? 0);
        }

        $pontos = $visitas
            ->filter(fn (Visita $v) => $v->data->isSameDay($dia) && (int) ($v->roteiro?->promotor_id ?? 0) === $promotor)
            ->filter(fn (Visita $v) => $v->pdv->temCoordenadas())
            ->values()
            ->map(fn (Visita $v, int $i) => [
                'n' => $i + 1,
                'lat' => $v->pdv->lat,
                'lng' => $v->pdv->lng,
                'raio' => $v->pdv->raio_m,
                'pdv' => $v->pdv->nome,
                'industria' => $v->industria->nome,
            ])
            ->all();

        return ['dia' => $dia->toDateString(), 'promotor' => $promotor, 'pontos' => $pontos];
    }
}
