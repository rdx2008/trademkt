<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\Perfil;
use App\Http\Controllers\Controller;
use App\Models\ModeloColeta;
use App\Models\Rede;
use App\Models\User;
use App\Services\Catalogo\ImportacaoSkus;
use App\Services\Coletas\Formulario;
use App\Services\Coletas\ModelosDePartida;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Modelos de coleta (fase 10): a indústria cria os formulários que o promotor responde na visita
 * (preço, validade, estoque, share, checklist). Admin da instalação e gerente de trade da marca; a
 * agência só cria na indústria gerida por ela.
 */
class ModeloColetaController extends Controller
{
    public function __construct(private ImportacaoSkus $skus) {}

    /** @return Collection<int, string> indústrias em que o usuário cria modelos */
    private function industrias(User $eu)
    {
        abort_unless($eu->temPerfil(Perfil::AdminInstalacao, Perfil::GerenteTrade), 403);

        return $this->skus->industriasDoUsuario($eu);
    }

    private function doUsuario(User $eu, int $id): ModeloColeta
    {
        return ModeloColeta::whereIn('industria_id', $this->industrias($eu)->keys())->findOrFail($id);
    }

    public function index(Request $request): View
    {
        $industrias = $this->industrias($request->user());

        return view('tenant.coletas.modelos.index', [
            'modelos' => ModeloColeta::with('industria')->whereIn('industria_id', $industrias->keys())->orderByDesc('ativo')->orderBy('nome')->get(),
            'partidas' => ModelosDePartida::todos(),
        ]);
    }

    public function create(Request $request): View
    {
        $industrias = $this->industrias($request->user());
        $partida = ModelosDePartida::todos()[(string) $request->query('partida')] ?? null;

        return view('tenant.coletas.modelos.form', $this->dados($industrias, new ModeloColeta([
            'nome' => $partida['nome'] ?? '', 'tipo' => $partida['tipo'] ?? 'livre', 'ativo' => true,
            'campos' => $partida['campos'] ?? [['rotulo' => '', 'tipo' => 'sim_nao', 'obrigatorio' => false, 'por_sku' => false, 'opcoes' => []]],
        ])));
    }

    public function store(Request $request): RedirectResponse
    {
        $industrias = $this->industrias($request->user());
        $dados = $this->validar($request, $industrias);
        ModeloColeta::create($dados + ['versao' => 1])->registrarVersao();

        return redirect()->route('coletas.modelos.index')->with('status', 'Modelo de coleta criado.');
    }

    public function edit(Request $request, int $modelo): View
    {
        $industrias = $this->industrias($request->user());

        return view('tenant.coletas.modelos.form', $this->dados($industrias, $this->doUsuario($request->user(), $modelo)));
    }

    public function update(Request $request, int $modelo): RedirectResponse
    {
        $industrias = $this->industrias($request->user());
        $registro = $this->doUsuario($request->user(), $modelo);
        $dados = $this->validar($request, $industrias);
        unset($dados['industria_id']);

        // Mudou o que se pergunta: nova versão, para as respostas antigas continuarem com a definição delas.
        $mudouDefinicao = $dados['campos'] !== $registro->campos;
        $registro->fill($dados);
        if ($mudouDefinicao) {
            $registro->versao++;
        }
        $registro->save();
        if ($mudouDefinicao) {
            $registro->registrarVersao();
        }

        return redirect()->route('coletas.modelos.index')->with('status', 'Modelo atualizado'.($mudouDefinicao ? ' (versão '.$registro->versao.').' : '.'));
    }

    public function ativo(Request $request, int $modelo): RedirectResponse
    {
        $registro = $this->doUsuario($request->user(), $modelo);
        $registro->update(['ativo' => $request->boolean('ativo')]);

        return redirect()->route('coletas.modelos.index')->with('status', $registro->ativo ? 'Modelo ativado.' : 'Modelo desativado: o promotor deixa de recebê-lo.');
    }

    /** @return array<string, mixed> */
    private function validar(Request $request, $industrias): array
    {
        $dados = $request->validate([
            'industria_id' => ['required', Rule::in($industrias->keys()->all())],
            'nome' => ['required', 'string', 'max:120'],
            'tipo' => ['required', Rule::in(array_keys(ModeloColeta::TIPOS))],
            'campos' => ['required', 'array'],
            'redes' => ['nullable', 'array'],
            'redes.*' => ['integer', Rule::exists('redes', 'id')],
        ], ['industria_id.in' => 'Indústria inválida.']);
        $dados['campos'] = Formulario::normalizarCampos($request->input('campos'));
        $dados['redes'] = array_values(array_map('intval', $dados['redes'] ?? [])) ?: null;
        $dados['ativo'] = $request->boolean('ativo');

        return $dados;
    }

    /** @return array<string, mixed> */
    private function dados($industrias, ModeloColeta $modelo): array
    {
        return [
            'modelo' => $modelo,
            'industrias' => $industrias,
            'redes' => Rede::orderBy('nome')->pluck('nome', 'id'),
            'tiposModelo' => ModeloColeta::TIPOS,
            'tiposCampo' => ModeloColeta::TIPOS_CAMPO,
        ];
    }
}
