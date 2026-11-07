<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\Perfil;
use App\Http\Controllers\Controller;
use App\Models\Agencia;
use App\Models\Visita;
use App\Services\Operacao\Equipe;
use App\Services\Roteiros\Atribuicao;
use App\Services\Roteiros\GeradorVisitas;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Roteiros da semana: visitas por promotor e por dia. A agência atribui e reordena;
 * a indústria e o admin da instalação só acompanham.
 */
class RoteiroController extends Controller
{
    public function semana(Request $request, Equipe $equipe): View
    {
        $eu = $request->user();
        $inicio = $this->inicioDaSemana($request->input('semana'));
        $dias = collect(CarbonPeriod::create($inicio, $inicio->addDays(6)))->map(fn ($d) => CarbonImmutable::parse($d));

        // A indústria acompanha todas as suas prestadoras, ou filtra uma delas.
        $agencias = $eu->perfil === Perfil::AdminInstalacao || $eu->perfil->exigeIndustria()
            ? Agencia::visiveisPara($eu)->orderBy('nome')->pluck('nome', 'id')
            : collect();
        $agenciaId = match (true) {
            $eu->perfil->exigeAgencia() => $eu->agencia_id,
            $eu->perfil === Perfil::AdminInstalacao => $request->integer('agencia_id') ?: $agencias->keys()->first(),
            default => $request->integer('agencia_id') ?: null,
        };

        if (! $eu->perfil->exigeAgencia() && $agenciaId !== null) {
            abort_unless($agencias->has($agenciaId), 403);
        }

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
        // Com várias agências na tela, a linha é identificada por "agência:promotor", porque o
        // mesmo id de promotor pode existir em duas agências.
        $chave = fn (?int $agencia, int $promotor) => $agenciaId ? (string) $promotor : "{$agencia}:{$promotor}";
        $linhas = collect();
        if ($podeEditar && $agenciaId) {
            foreach ($equipe->promotores($agenciaId) as $p) {
                $linhas[$chave($agenciaId, $p->id)] = ['id' => $chave($agenciaId, $p->id), 'nome' => $p->nome, 'ativo' => $p->ativo];
            }
        }
        foreach ($visitas as $v) {
            if ($v->roteiro && ! $linhas->has($chave($v->agencia_id, $v->roteiro->promotor_id))) {
                $linhas[$chave($v->agencia_id, $v->roteiro->promotor_id)] = ['id' => $chave($v->agencia_id, $v->roteiro->promotor_id), 'nome' => $v->nomePromotor() ?? 'Promotor '.$v->roteiro->promotor_id, 'ativo' => $v->promotor()?->ativo ?? false];
            }
        }
        $promotores = $linhas->sortBy('nome')->values()->map(fn ($l) => (object) $l);

        $grade = $visitas->groupBy(fn (Visita $v) => ($v->roteiro ? $chave($v->agencia_id, $v->roteiro->promotor_id) : '0').'|'.$v->data->toDateString());

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
            'mapa' => $this->mapaDoDia($request, $visitas, $dias, $promotores, $chave),
        ]);
    }

    /** Move uma visita para outro promotor e/ou dia e define a ordem da célula de destino. */
    public function mover(Request $request, Visita $visita, Atribuicao $atribuicao, Equipe $equipe): JsonResponse|RedirectResponse
    {
        $eu = $request->user();
        abort_unless(Visita::podeAtribuir($eu) && $visita->agencia_id === $eu->agencia_id, 403);

        $dados = $request->validate([
            'promotor_id' => ['nullable', 'integer'],
            'data' => ['required', 'date_format:Y-m-d'],
            'ordem' => ['nullable', 'array'],
            'ordem.*' => ['integer'],
        ]);

        $promotor = isset($dados['promotor_id']) ? $equipe->promotorAtivo($visita->agencia_id, (int) $dados['promotor_id']) : null;
        if (isset($dados['promotor_id']) && ! $promotor) {
            throw ValidationException::withMessages(['promotor_id' => 'Escolha um promotor ativo da agência responsável pela visita.']);
        }

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
     * @return array{dia: string, promotor: string, pontos: list<array>}
     */
    private function mapaDoDia(Request $request, Collection $visitas, Collection $dias, Collection $promotores, callable $chave): array
    {
        $dia = $request->input('dia');
        $dia = $dias->first(fn ($d) => $d->toDateString() === $dia)
            ?? $dias->first(fn ($d) => $d->isToday())
            ?? $dias->first();

        $promotor = (string) $request->input('promotor', $promotores->first()?->id ?? '0');

        $pontos = $visitas
            ->filter(fn (Visita $v) => $v->data->isSameDay($dia) && ($v->roteiro ? $chave($v->agencia_id, $v->roteiro->promotor_id) : '0') === $promotor)
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
