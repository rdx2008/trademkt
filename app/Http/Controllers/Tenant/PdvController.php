<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\CanalPdv;
use App\Enums\Perfil;
use App\Http\Controllers\Controller;
use App\Models\Pdv;
use App\Models\Rede;
use App\Models\Regiao;
use App\Rules\Cnpj;
use App\Services\Geocoding\Geocodificador;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PdvController extends Controller
{
    public function __construct(private Geocodificador $geocodificador) {}

    public function index(Request $request): View
    {
        $eu = $request->user();

        $pdvs = Pdv::query()
            ->visiveisPara($eu)
            ->with(['rede', 'regiao'])
            ->when($request->filled('busca'), function (Builder $q) use ($request) {
                $busca = trim((string) $request->string('busca'));
                $digitos = Cnpj::limpar($busca);
                $q->where(function (Builder $s) use ($busca, $digitos) {
                    $s->where('nome', 'like', "%{$busca}%")
                        ->orWhere('cidade', 'like', "%{$busca}%")
                        ->orWhere('bairro', 'like', "%{$busca}%");
                    if (strlen($digitos) >= 4) {
                        $s->orWhere('cnpj', 'like', "%{$digitos}%");
                    }
                });
            })
            ->when($request->filled('rede_id'), fn ($q) => $q->where('rede_id', $request->integer('rede_id')))
            ->when($request->filled('regiao_id'), fn ($q) => $q->where('regiao_id', $request->integer('regiao_id')))
            ->when($request->filled('canal'), fn ($q) => $q->where('canal', $request->string('canal')))
            ->when($request->filled('uf'), fn ($q) => $q->where('uf', mb_strtoupper((string) $request->string('uf'))))
            ->when($request->filled('situacao'), fn ($q) => $q->where('ativo', $request->input('situacao') === 'ativos'))
            ->orderBy('nome')
            ->paginate(30)
            ->withQueryString();

        return view('tenant.pdvs.index', [
            'pdvs' => $pdvs,
            'podeEditar' => $this->podeEditar($eu->perfil),
            'redes' => Rede::orderBy('nome')->pluck('nome', 'id'),
            'regioes' => Regiao::orderBy('nome')->pluck('nome', 'id'),
            'canais' => CanalPdv::opcoes(),
        ]);
    }

    public function create(): View
    {
        return view('tenant.pdvs.form', $this->dadosFormulario(new Pdv));
    }

    public function store(Request $request): RedirectResponse
    {
        $pdv = new Pdv;
        $this->salvar($request, $pdv);

        return redirect()->route('pdvs.index')->with('status', $this->mensagem('PDV cadastrado.', $pdv));
    }

    public function edit(Pdv $pdv): View
    {
        return view('tenant.pdvs.form', $this->dadosFormulario($pdv));
    }

    public function update(Request $request, Pdv $pdv): RedirectResponse
    {
        $this->salvar($request, $pdv);

        return redirect()->route('pdvs.index')->with('status', $this->mensagem('PDV atualizado.', $pdv));
    }

    public static function podeEditar(Perfil $perfil): bool
    {
        return in_array($perfil, [Perfil::AdminInstalacao, Perfil::AdminAgencia], true);
    }

    private function salvar(Request $request, Pdv $pdv): void
    {
        $request->merge(['cnpj' => Cnpj::limpar($request->input('cnpj'))]);

        $dados = $request->validate([
            'cnpj' => ['required', new Cnpj, Rule::unique('pdvs', 'cnpj')->ignore($pdv->id)],
            'nome' => ['required', 'string', 'max:150'],
            'rede_id' => ['nullable', Rule::exists('redes', 'id')],
            'regiao_id' => ['nullable', Rule::exists('regioes', 'id')],
            'canal' => ['nullable', Rule::enum(CanalPdv::class)],
            'cep' => ['nullable', 'string', 'max:9'],
            'logradouro' => ['nullable', 'string', 'max:150'],
            'numero' => ['nullable', 'string', 'max:20'],
            'complemento' => ['nullable', 'string', 'max:80'],
            'bairro' => ['nullable', 'string', 'max:80'],
            'cidade' => ['required', 'string', 'max:80'],
            'uf' => ['required', 'string', 'size:2', 'alpha'],
            'lat' => ['nullable', 'required_with:lng', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'required_with:lat', 'numeric', 'between:-180,180'],
            'raio_m' => ['nullable', 'integer', 'between:'.Pdv::RAIO_MINIMO.','.Pdv::RAIO_MAXIMO],
        ], [
            'raio_m.between' => 'O raio de check-in deve ficar entre '.Pdv::RAIO_MINIMO.' e '.Pdv::RAIO_MAXIMO.' metros.',
            'cnpj.unique' => 'Já existe um PDV com este CNPJ.',
        ], [
            'cnpj' => 'CNPJ',
            'uf' => 'UF',
            'raio_m' => 'raio de check-in',
        ]);

        $dados['raio_m'] ??= Pdv::RAIO_PADRAO;
        $dados['ativo'] = $request->boolean('ativo');

        $latAntes = $pdv->lat;
        $lngAntes = $pdv->lng;
        $enderecoAntes = $pdv->exists ? $pdv->enderecoCompleto() : null;

        $pdv->fill($dados);

        $informouCoordenadas = isset($dados['lat'], $dados['lng']);
        $moveuPino = $informouCoordenadas
            && (! $pdv->exists || abs($dados['lat'] - (float) $latAntes) > 1e-7 || abs($dados['lng'] - (float) $lngAntes) > 1e-7);

        if ($moveuPino) {
            // Coordenada informada ou pino arrastado no mapa: vale o ajuste manual.
            $pdv->coordenadas_origem = 'manual';
        } elseif (! $informouCoordenadas || ($pdv->coordenadas_origem !== 'manual' && $enderecoAntes !== $pdv->enderecoCompleto())) {
            // Sem coordenadas, ou endereço mudou sem ajuste manual: consulta o geocoding.
            $coordenadas = $this->geocodificador->coordenadas($pdv->enderecoCompleto());
            $pdv->lat = $coordenadas['lat'] ?? ($informouCoordenadas ? $pdv->lat : null);
            $pdv->lng = $coordenadas['lng'] ?? ($informouCoordenadas ? $pdv->lng : null);
            $pdv->coordenadas_origem = $coordenadas ? 'geocoding' : ($pdv->temCoordenadas() ? $pdv->coordenadas_origem : null);
        }

        $pdv->save();
    }

    private function mensagem(string $texto, Pdv $pdv): string
    {
        return $pdv->temCoordenadas()
            ? $texto
            : $texto.' Endereço não localizado: ajuste o pino no mapa para liberar o check-in.';
    }

    private function dadosFormulario(Pdv $pdv): array
    {
        return [
            'pdv' => $pdv,
            'redes' => Rede::where('ativo', true)->orWhere('id', $pdv->rede_id)->orderBy('nome')->pluck('nome', 'id'),
            'regioes' => Regiao::where('ativo', true)->orWhere('id', $pdv->regiao_id)->orderBy('nome')->pluck('nome', 'id'),
            'canais' => CanalPdv::opcoes(),
            'geocodingDisponivel' => $this->geocodificador->disponivel(),
        ];
    }
}
