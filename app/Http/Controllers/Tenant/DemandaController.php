<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\OrigemVisita;
use App\Enums\Perfil;
use App\Http\Controllers\Controller;
use App\Models\Demanda;
use App\Models\Roteiro;
use App\Models\Sku;
use App\Models\User;
use App\Models\Visita;
use App\Services\Operacao\AlertasOperacao;
use App\Services\Operacao\Equipe;
use App\Services\Operacao\MarcaDagua;
use App\Services\Painel\ResumoOperacional;
use App\Support\Contexto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DemandaController extends Controller
{
    private function podeAbrir(User $u): bool
    {
        return $u->temPerfil(Perfil::AdminInstalacao, Perfil::GerenteTrade, Perfil::GerentePdv, Perfil::AdminAgencia, Perfil::Supervisor);
    }

    private function contratos(User $u)
    {
        return app(ResumoOperacional::class)->contratos($u)->vigentesEm()->whereHas('pdv', fn ($p) => $p->where('ativo', true))->whereHas('agencia', fn ($a) => $a->where('ativo', true))->whereHas('industria', fn ($i) => $i->where('ativo', true));
    }

    public function index(Request $request)
    {
        $request->validate(['status' => ['nullable', Rule::in(['aberta', 'aprovada', 'recusada', 'atendida', 'atrasada'])]]);
        $consulta = Demanda::visiveisPara($request->user());
        if ($request->status === 'atrasada') {
            $consulta->where('prazo_em', '<', now())->whereIn('status', ['aberta', 'aprovada']);
        } elseif ($request->filled('status')) {
            $consulta->where('status', $request->status);
        }

        return view('tenant.demandas.index', ['demandas' => $consulta->with(['pdv', 'industria', 'agencia', 'visita'])->latest()->paginate(20)->withQueryString(), 'podeAbrir' => $this->podeAbrir($request->user())]);
    }

    public function create(Request $request)
    {
        abort_unless($this->podeAbrir($request->user()), 403);
        $request->validate(['tipo' => ['nullable', Rule::in(['visita', 'ruptura'])], 'contrato_id' => ['nullable', 'integer']]);
        $contratos = $this->contratos($request->user())->with(['pdv', 'industria', 'agencia'])->get();
        $contrato = $request->filled('contrato_id') ? $contratos->firstWhere('id', $request->integer('contrato_id')) : null;
        abort_if($request->filled('contrato_id') && ! $contrato, 404);

        return view('tenant.demandas.form', ['contratos' => $contratos, 'contrato' => $contrato, 'tipo' => $request->input('tipo', 'visita'), 'skus' => $contrato ? Sku::where('industria_id', $contrato->industria_id)->where('ativo', true)->orderBy('descricao')->get() : collect()]);
    }

    public function store(Request $request, MarcaDagua $marca, AlertasOperacao $alertas)
    {
        abort_unless($this->podeAbrir($request->user()), 403);
        $dados = $request->validate([
            'uuid' => ['required', 'uuid'], 'contrato_id' => ['required', 'integer'], 'tipo' => ['required', Rule::in(['visita', 'ruptura'])],
            'motivo' => ['required', Rule::in(['reposicao', 'desarrumacao', 'outro', 'ruptura'])],
            'observacao' => ['nullable', 'required_if:motivo,outro', 'string', 'max:2000'],
            'skus' => ['nullable', 'required_if:tipo,ruptura', 'array', 'min:1', 'max:500'], 'skus.*' => ['integer', 'distinct'],
            'foto' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:5120', 'dimensions:max_width=4096,max_height=4096'],
        ]);
        $gravado = null;
        try {
            return DB::connection('tenant')->transaction(function () use ($request, $dados, $marca, $alertas, &$gravado) {
                $contrato = $this->contratos($request->user())->lockForUpdate()->findOrFail($dados['contrato_id']);
                $skus = collect($dados['skus'] ?? [])->map(fn ($i) => (int) $i)->sort()->values()->all();
                if (Sku::where('industria_id', $contrato->industria_id)->where('ativo', true)->whereIn('id', $skus)->count() !== count($skus)) {
                    throw ValidationException::withMessages(['skus' => 'Há produto inativo ou de outra indústria.']);
                }
                if (($dados['tipo'] === 'ruptura') !== ($dados['motivo'] === 'ruptura')) {
                    throw ValidationException::withMessages(['motivo' => 'Selecione um motivo compatível com o tipo de solicitação.']);
                }
                $hash = hash('sha256', json_encode([$contrato->id, $dados['tipo'], $dados['motivo'], trim($dados['observacao'] ?? ''), $skus, $request->hasFile('foto') ? hash_file('sha256', $request->file('foto')->getRealPath()) : null]));
                if ($existente = Demanda::where('uuid', $dados['uuid'])->first()) {
                    abort_unless($existente->solicitante_id === $request->user()->id && $existente->payload_hash === $hash, 409, 'Identificador já utilizado em outra solicitação.');

                    return redirect()->route('demandas.show', $existente);
                }
                if ($request->hasFile('foto')) {
                    $gravado = $marca->gravar($request->file('foto'), 'demandas/'.$dados['uuid'].'.jpg', ['Relato de '.$request->user()->perfil->label(), 'PDV '.$contrato->pdv_id.' - '.$contrato->pdv->nome, 'Registrado '.now()->toIso8601String()]);
                }
                $demanda = Demanda::create(['uuid' => $dados['uuid'], 'payload_hash' => $hash, 'solicitante_id' => $request->user()->id, 'solicitante_agencia_id' => Contexto::agenciaLocal()?->id, 'contrato_id' => $contrato->id, 'pdv_id' => $contrato->pdv_id, 'industria_id' => $contrato->industria_id, 'agencia_id' => $contrato->agencia_id, 'tipo' => $dados['tipo'], 'motivo' => $dados['motivo'], 'observacao' => trim($dados['observacao'] ?? ''), 'skus' => $skus, 'foto' => $gravado['caminho'] ?? null, 'foto_disco' => $gravado['disco'] ?? 'local', 'prazo_em' => now()->endOfDay()]);
                $alertas->emitir('demanda_aberta', $demanda, $demanda->tipo === 'ruptura' ? 'Ruptura relatada no PDV' : 'Nova solicitação de visita', $contrato->pdv->nome.' · Aguardando decisão da agência.');

                return redirect()->route('demandas.show', $demanda)->with('status', 'Solicitação enviada à agência. Prazo de atendimento: hoje.');
            });
        } catch (\Throwable $e) {
            if ($gravado) {
                Storage::disk($gravado['disco'])->delete($gravado['caminho']);
            }
            throw $e;
        }
    }

    public function show(Request $request, int $demanda, Equipe $equipe)
    {
        $d = Demanda::visiveisPara($request->user())->with(['pdv', 'industria', 'agencia', 'visita.roteiro'])->findOrFail($demanda);
        $podeGerir = Visita::podeAtribuir($request->user()) && $request->user()->agencia_id === $d->agencia_id;

        return view('tenant.demandas.show', ['demanda' => $d, 'podeGerir' => $podeGerir, 'promotores' => $podeGerir ? $equipe->promotores($d->agencia_id, true, true, $d->pdv?->cidade) : collect(), 'skus' => Sku::where('industria_id', $d->industria_id)->whereIn('id', $d->skus ?? [])->get()]);
    }

    public function decidir(Request $request, int $demanda, AlertasOperacao $alertas, Equipe $equipe)
    {
        abort_unless(Visita::podeAtribuir($request->user()), 403);
        $dados = $request->validate(['decisao' => ['required', Rule::in(['aprovar', 'recusar'])], 'promotor_id' => ['nullable', 'required_if:decisao,aprovar', 'integer'], 'resposta' => ['nullable', 'required_if:decisao,recusar', 'string', 'max:2000']]);
        // A equipe é lida antes da transação: vem de outro banco.
        $promotor = $dados['decisao'] === 'aprovar' ? $equipe->promotorAtivo($request->user()->agencia_id, (int) $dados['promotor_id'], today()->toDateString()) : null;
        DB::connection('tenant')->transaction(function () use ($request, $demanda, $dados, $alertas, $promotor) {
            $d = Demanda::where('agencia_id', $request->user()->agencia_id)->lockForUpdate()->findOrFail($demanda);
            if ($d->status !== 'aberta') {
                abort_unless($dados['decisao'] === 'aprovar' && $d->status === 'aprovada' && $d->visita?->roteiro?->promotor_id === $request->integer('promotor_id'), 409, 'A solicitação já foi decidida.');

                return;
            }
            if ($dados['decisao'] === 'recusar') {
                $d->update(['status' => 'recusada', 'liberada_por' => $request->user()->id, 'resposta' => $dados['resposta']]);
                $alertas->emitir('demanda_recusada', $d, 'Solicitação recusada pela agência', $d->pdv->nome.' · '.$d->resposta);

                return;
            }
            if (! $promotor) {
                throw ValidationException::withMessages(['promotor_id' => 'Escolha um promotor ativo da sua agência.']);
            }
            $roteiro = Roteiro::doPromotor($d->agencia_id, $promotor->id, today()->toDateString())->first()
                ?? Roteiro::create(['promotor_id' => $promotor->id, 'agencia_id' => $d->agencia_id, 'data' => today(), 'supervisor_id' => $request->user()->id]);
            $visita = Visita::create(['roteiro_id' => $roteiro->id, 'data' => today(), 'pdv_id' => $d->pdv_id, 'industria_id' => $d->industria_id, 'agencia_id' => $d->agencia_id, 'origem' => OrigemVisita::Demanda]);
            $d->update(['status' => 'aprovada', 'liberada_por' => $request->user()->id, 'visita_id' => $visita->id, 'resposta' => $dados['resposta'] ?? null]);
            $alertas->emitir('demanda_aprovada', $d, 'Visita liberada pela agência', $d->pdv->nome.' · Promotor '.$promotor->nome);
        });

        return back()->with('status', 'Decisão registrada.');
    }

    public function foto(Request $request, int $demanda)
    {
        $d = Demanda::visiveisPara($request->user())->findOrFail($demanda);
        abort_unless($d->foto && Storage::disk($d->foto_disco)->exists($d->foto), 404);

        return Storage::disk($d->foto_disco)->response($d->foto, headers: ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
