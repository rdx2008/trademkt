<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\Perfil;
use App\Enums\TipoEmbalagem;
use App\Http\Controllers\Controller;
use App\Models\Industria;
use App\Models\Sku;
use App\Models\User;
use App\Rules\Gtin;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SkuController extends Controller
{
    public function index(Request $request): View
    {
        $eu = $request->user();

        $skus = Sku::query()
            ->visiveisPara($eu)
            ->with(['industria', 'embalagens'])
            ->when($request->filled('busca'), function (Builder $q) use ($request) {
                $busca = trim((string) $request->string('busca'));
                $gtin = Gtin::normalizar($busca);
                $q->where(function (Builder $s) use ($busca, $gtin) {
                    $s->where('codigo', 'like', "%{$busca}%")
                        ->orWhere('descricao', 'like', "%{$busca}%")
                        ->orWhereHas('embalagens', fn (Builder $e) => $e->where('codigo', 'like', "%{$busca}%")
                            ->when(ctype_digit($gtin) && strlen($gtin) >= 4, fn ($e) => $e->orWhere('gtin', 'like', "%{$gtin}%")));
                });
            })
            ->when($request->filled('industria_id'), fn ($q) => $q->where('industria_id', $request->integer('industria_id')))
            ->when($request->filled('categoria'), fn ($q) => $q->where('categoria', $request->string('categoria')))
            ->when($request->filled('situacao'), fn ($q) => $q->where('ativo', $request->input('situacao') === 'ativos'))
            ->when($request->input('alerta') === 'gtin', fn ($q) => $q->comAlertaDeGtin())
            ->orderBy('descricao')
            ->paginate(30)
            ->withQueryString();

        $industrias = in_array($eu->perfil, [Perfil::GerenteTrade, Perfil::Representante], true)
            ? collect()
            : Industria::orderBy('nome')->pluck('nome', 'id');

        return view('tenant.skus.index', [
            'skus' => $skus,
            'eu' => $eu,
            'podeCadastrar' => $this->podeCadastrar($eu),
            'industrias' => $industrias,
            'categorias' => Sku::visiveisPara($eu)->whereNotNull('categoria')->distinct()->orderBy('categoria')->pluck('categoria'),
        ]);
    }

    public function create(Request $request): View
    {
        abort_unless($this->podeCadastrar($request->user()), 403);

        return view('tenant.skus.form', $this->dadosFormulario($request->user(), new Sku));
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->podeCadastrar($request->user()), 403);

        $sku = $this->salvar($request, new Sku);

        return redirect()->route('skus.index')->with('status', $this->mensagem('SKU cadastrado.', $sku));
    }

    public function edit(Request $request, Sku $sku): View
    {
        abort_unless($sku->podeSerEditadoPor($request->user()), 403);

        return view('tenant.skus.form', $this->dadosFormulario($request->user(), $sku));
    }

    public function update(Request $request, Sku $sku): RedirectResponse
    {
        abort_unless($sku->podeSerEditadoPor($request->user()), 403);

        $this->salvar($request, $sku);

        return redirect()->route('skus.index')->with('status', $this->mensagem('SKU atualizado.', $sku));
    }

    /** Foto do produto, servida só para quem pode ver o SKU. */
    public function foto(Request $request, Sku $sku): StreamedResponse
    {
        abort_unless(Sku::visiveisPara($request->user())->whereKey($sku->id)->exists(), 404);
        abort_unless($sku->foto && Storage::disk('local')->exists($sku->foto), 404);

        return Storage::disk('local')->response($sku->foto, headers: ['Cache-Control' => 'private, max-age=86400']);
    }

    private function podeCadastrar(User $usuario): bool
    {
        return $usuario->temPerfil(Perfil::AdminInstalacao, Perfil::GerenteTrade);
    }

    private function salvar(Request $request, Sku $sku): Sku
    {
        $eu = $request->user();

        // Linhas de embalagem em branco são ignoradas.
        $request->merge(['embalagens' => array_values(array_filter(
            (array) $request->input('embalagens', []),
            fn ($e) => is_array($e) && collect([$e['gtin'] ?? null, $e['codigo'] ?? null, $e['quantidade'] ?? null])
                ->contains(fn ($v) => trim((string) $v) !== '')
        ))]);

        $industriaId = $eu->perfil === Perfil::GerenteTrade
            ? $eu->industria_id
            : ($request->integer('industria_id') ?: $sku->industria_id);

        $dados = $request->validate([
            'industria_id' => [
                Rule::requiredIf($eu->perfil === Perfil::AdminInstalacao),
                'nullable',
                Rule::exists('industrias', 'id')->where(fn ($q) => $q->where('ativo', true)->orWhere('id', $sku->industria_id)),
            ],
            'codigo' => [
                'required', 'string', 'max:40',
                Rule::unique('skus', 'codigo')->where('industria_id', $industriaId)->ignore($sku->id),
            ],
            'descricao' => ['required', 'string', 'max:200'],
            'categoria' => ['nullable', 'string', 'max:80'],
            'foto' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'remover_foto' => ['boolean'],
            'embalagens' => ['array', 'max:20'],
            'embalagens.*.id' => $sku->exists
                ? ['nullable', 'integer', Rule::exists('sku_embalagens', 'id')->where('sku_id', $sku->id)]
                : ['prohibited'],
            'embalagens.*.tipo' => ['required', Rule::enum(TipoEmbalagem::class)],
            'embalagens.*.quantidade' => ['required', 'integer', 'min:1', 'max:100000'],
            'embalagens.*.gtin' => ['nullable', 'string', 'max:20', new Gtin],
            'embalagens.*.codigo' => ['nullable', 'string', 'max:40'],
        ], [
            'codigo.unique' => 'Já existe um SKU com este código nesta indústria.',
        ], [
            'codigo' => 'código',
            'descricao' => 'descrição',
            'embalagens.*.gtin' => 'GTIN',
            'embalagens.*.quantidade' => 'quantidade da embalagem',
            'embalagens.*.tipo' => 'tipo da embalagem',
        ]);

        $fotoAntiga = $sku->foto;
        $fotoNova = $request->file('foto')?->store("skus/{$industriaId}", 'local');

        DB::transaction(function () use ($request, $sku, $dados, $industriaId, $fotoNova) {
            $sku->fill([
                'industria_id' => $industriaId,
                'codigo' => trim($dados['codigo']),
                'descricao' => trim($dados['descricao']),
                'categoria' => filled($dados['categoria'] ?? null) ? trim($dados['categoria']) : null,
                'ativo' => $request->boolean('ativo'),
            ]);

            if ($fotoNova) {
                $sku->foto = $fotoNova;
            } elseif ($request->boolean('remover_foto')) {
                $sku->foto = null;
            }

            $sku->save();

            // Sincroniza as embalagens: atualiza as que vieram com id, cria as novas e apaga as retiradas.
            $manter = [];
            foreach ($dados['embalagens'] ?? [] as $linha) {
                $embalagem = isset($linha['id']) ? $sku->embalagens()->findOrNew($linha['id']) : $sku->embalagens()->make();
                $embalagem->fill([
                    'tipo' => $linha['tipo'],
                    'quantidade' => (int) $linha['quantidade'],
                    'gtin' => $linha['gtin'] ?? null,
                    'codigo' => filled($linha['codigo'] ?? null) ? trim($linha['codigo']) : null,
                ]);
                $sku->embalagens()->save($embalagem);
                $manter[] = $embalagem->id;
            }
            $sku->embalagens()->whereNotIn('id', $manter)->delete();
        });

        if ($fotoAntiga && $fotoAntiga !== $sku->foto) {
            Storage::disk('local')->delete($fotoAntiga);
        }

        return $sku;
    }

    private function mensagem(string $texto, Sku $sku): string
    {
        $alertas = $sku->embalagens()->where('gtin_valido', false)->pluck('gtin');

        return $alertas->isEmpty()
            ? $texto
            : $texto.' Atenção: GTIN com dígito verificador errado ('.$alertas->implode(', ').'). Ficou marcado para revisão.';
    }

    private function dadosFormulario(User $eu, Sku $sku): array
    {
        $embalagens = $sku->exists
            ? $sku->embalagensOrdenadas()->map(fn ($e) => [
                'id' => $e->id, 'tipo' => $e->tipo->value, 'quantidade' => $e->quantidade,
                'gtin' => $e->gtin, 'codigo' => $e->codigo, 'alerta' => $e->gtinComAlerta(),
            ])->all()
            : array_map(fn (TipoEmbalagem $t) => ['tipo' => $t->value, 'quantidade' => $t === TipoEmbalagem::Unidade ? 1 : null], TipoEmbalagem::cases());

        return [
            'sku' => $sku,
            'eu' => $eu,
            'industrias' => Industria::where('ativo', true)->orWhere('id', $sku->industria_id)->orderBy('nome')->pluck('nome', 'id'),
            'categorias' => Sku::visiveisPara($eu)->whereNotNull('categoria')->distinct()->orderBy('categoria')->pluck('categoria'),
            'tipos' => TipoEmbalagem::opcoes(),
            'embalagens' => old('embalagens', $embalagens),
        ];
    }
}
