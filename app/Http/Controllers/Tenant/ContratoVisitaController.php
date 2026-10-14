<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\Perfil;
use App\Http\Controllers\Controller;
use App\Models\Agencia;
use App\Models\ContratoVisita;
use App\Models\Industria;
use App\Models\Pdv;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ContratoVisitaController extends Controller
{
    public function index(Request $request): View
    {
        $eu = $request->user();
        $hoje = now()->toDateString();

        $contratos = ContratoVisita::query()
            ->visiveisPara($eu)
            ->with(['pdv', 'industria', 'agencia'])
            ->when($request->filled('pdv_id'), fn ($q) => $q->where('pdv_id', $request->integer('pdv_id')))
            ->when($request->filled('busca'), function (Builder $q) use ($request) {
                $busca = '%'.trim((string) $request->string('busca')).'%';
                $q->whereHas('pdv', fn (Builder $p) => $p->where('nome', 'like', $busca)->orWhere('cidade', 'like', $busca));
            })
            ->when($request->filled('industria_id'), fn ($q) => $q->where('industria_id', $request->integer('industria_id')))
            ->when($request->filled('agencia_id'), fn ($q) => $q->where('agencia_id', $request->integer('agencia_id')))
            ->when($request->filled('frequencia'), fn ($q) => $q->where('frequencia_semana', $request->integer('frequencia')))
            ->when($request->input('situacao') === 'vigentes', fn ($q) => $q->vigentesEm(now()))
            ->when($request->input('situacao') === 'futuros', fn ($q) => $q->ativos()->whereDate('inicio', '>', $hoje))
            ->when($request->input('situacao') === 'encerrados', fn ($q) => $q->where(
                fn (Builder $s) => $s->where('ativo', false)->orWhereDate('fim', '<', $hoje)
            ))
            ->orderBy(Pdv::select('nome')->whereColumn('pdvs.id', 'contratos_visita.pdv_id'))
            ->orderBy('id')
            ->paginate(30)
            ->withQueryString();

        return view('tenant.contratos.index', [
            'contratos' => $contratos,
            'eu' => $eu,
            'podeCadastrar' => $eu->temPerfil(Perfil::AdminInstalacao, Perfil::AdminAgencia),
            'industrias' => $eu->perfil->exigeIndustria() ? collect() : Industria::orderBy('nome')->pluck('nome', 'id'),
            'agencias' => $eu->perfil === Perfil::AdminInstalacao || $eu->perfil->exigeIndustria()
                ? Agencia::orderBy('nome')->pluck('nome', 'id')
                : collect(),
            'pdvFiltrado' => $request->filled('pdv_id') ? Pdv::visiveisPara($eu)->find($request->integer('pdv_id')) : null,
        ]);
    }

    public function create(Request $request): View
    {
        $contrato = new ContratoVisita([
            'pdv_id' => $request->integer('pdv_id') ?: null,
            'inicio' => now()->toDateString(),
            'frequencia_semana' => 1,
            'dias' => [],
        ]);

        return view('tenant.contratos.form', $this->dadosFormulario($request->user(), $contrato));
    }

    public function store(Request $request): RedirectResponse
    {
        $contrato = new ContratoVisita;
        $this->salvar($request, $contrato);

        return redirect()->route('contratos.index')->with('status', 'Contrato de visita cadastrado.');
    }

    public function edit(Request $request, ContratoVisita $contrato): View
    {
        abort_unless($contrato->podeSerEditadoPor($request->user()), 403);

        return view('tenant.contratos.form', $this->dadosFormulario($request->user(), $contrato));
    }

    public function update(Request $request, ContratoVisita $contrato): RedirectResponse
    {
        abort_unless($contrato->podeSerEditadoPor($request->user()), 403);

        $this->salvar($request, $contrato);

        return redirect()->route('contratos.index')->with('status', 'Contrato de visita atualizado.');
    }

    private function salvar(Request $request, ContratoVisita $contrato): void
    {
        $eu = $request->user();
        $frequencia = $request->integer('frequencia_semana');

        $dados = $request->validate([
            'pdv_id' => [
                'required',
                Rule::exists('pdvs', 'id')->where(fn ($q) => $q->where('ativo', true)->orWhere('id', $contrato->pdv_id)),
            ],
            'industria_id' => [
                'required',
                Rule::exists('industrias', 'id')->where(fn ($q) => $q->where('ativo', true)->orWhere('id', $contrato->industria_id)),
            ],
            'agencia_id' => [
                Rule::requiredIf($eu->perfil === Perfil::AdminInstalacao),
                'nullable',
                Rule::exists('agencias', 'id')->where(fn ($q) => $q->where('ativo', true)->orWhere('id', $contrato->agencia_id)),
            ],
            'frequencia_semana' => ['required', 'integer', Rule::in(ContratoVisita::FREQUENCIAS)],
            'dias' => ['required', 'array', in_array($frequencia, ContratoVisita::FREQUENCIAS, true) ? "size:{$frequencia}" : 'array'],
            'dias.*' => ['integer', 'between:1,7', 'distinct'],
            'inicio' => ['required', 'date'],
            'fim' => ['nullable', 'date', 'after_or_equal:inicio'],
        ], [
            'frequencia_semana.in' => 'A frequência deve ser 1, 2 ou 3 visitas por semana.',
            'dias.required' => 'Marque os dias da semana das visitas.',
            'dias.size' => 'Marque exatamente :size dia(s) da semana, um para cada visita da frequência contratada.',
            'dias.*.distinct' => 'Não repita o mesmo dia da semana.',
            'dias.*.between' => 'Dia da semana inválido.',
            'fim.after_or_equal' => 'O fim da vigência não pode ser antes do início.',
        ], [
            'pdv_id' => 'PDV',
            'industria_id' => 'indústria',
            'agencia_id' => 'agência',
            'frequencia_semana' => 'frequência',
            'inicio' => 'início da vigência',
            'fim' => 'fim da vigência',
        ]);

        // A agência só cadastra contratos que ela mesma executa.
        if ($eu->perfil === Perfil::AdminAgencia) {
            $dados['agencia_id'] = $eu->agencia_id;
        }

        $dias = array_map('intval', $dados['dias']);
        sort($dias);
        $dados['dias'] = $dias;
        $dados['ativo'] = $request->boolean('ativo');

        // Um PDV pode ter contratos de várias indústrias, mas não dois contratos ativos da mesma
        // indústria com vigências que se cruzam (geraria visitas em dobro).
        if ($dados['ativo']) {
            $conflito = ContratoVisita::sobrepostos((int) $dados['pdv_id'], (int) $dados['industria_id'], $dados['inicio'], $dados['fim'] ?? null)
                ->when($contrato->exists, fn ($q) => $q->whereKeyNot($contrato->id))
                ->with('agencia')
                ->first();

            if ($conflito) {
                throw ValidationException::withMessages([
                    'industria_id' => 'Este PDV já tem um contrato ativo desta indústria nesse período (agência '
                        .$conflito->agencia->nome.', desde '.$conflito->inicio->format('d/m/Y')
                        .($conflito->fim ? ' até '.$conflito->fim->format('d/m/Y') : ', sem data de fim')
                        .'). Encerre o anterior ou ajuste a vigência.',
                ]);
            }
        }

        $contrato->fill($dados)->save();
    }

    private function dadosFormulario(User $eu, ContratoVisita $contrato): array
    {
        return [
            'contrato' => $contrato,
            'eu' => $eu,
            'pdvs' => Pdv::query()
                ->where(fn ($q) => $q->where('ativo', true)->orWhere('id', $contrato->pdv_id))
                ->orderBy('nome')
                ->get(['id', 'nome', 'cidade', 'uf']),
            'industrias' => Industria::where('ativo', true)->orWhere('id', $contrato->industria_id)->orderBy('nome')->pluck('nome', 'id'),
            'agencias' => Agencia::where('ativo', true)->orWhere('id', $contrato->agencia_id)->orderBy('nome')->pluck('nome', 'id'),
            'diasSemana' => ContratoVisita::DIAS,
        ];
    }
}
