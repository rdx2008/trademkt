<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Cadastro de tabelas simples (nome + ativo), como regiões e redes.
 */
abstract class CadastroSimplesController extends Controller
{
    /** @return class-string<Model> */
    abstract protected function modelo(): string;

    /** Prefixo das rotas (ex.: "regioes"). */
    abstract protected function rota(): string;

    /** @return array{singular: string, plural: string, novo: string, feminino: bool} */
    abstract protected function rotulos(): array;

    public function index(Request $request): View
    {
        $modelo = $this->modelo();

        $itens = $modelo::query()
            ->withCount('pdvs')
            ->when($request->filled('busca'), fn ($q) => $q->where('nome', 'like', '%'.$request->string('busca').'%'))
            ->orderBy('nome')
            ->paginate(50)
            ->withQueryString();

        return view('tenant.cadastros-simples.index', [
            'itens' => $itens,
            'rota' => $this->rota(),
            'rotulos' => $this->rotulos(),
        ]);
    }

    public function create(): View
    {
        $modelo = $this->modelo();

        return $this->formulario(new $modelo(['ativo' => true]));
    }

    public function store(Request $request): RedirectResponse
    {
        $modelo = $this->modelo();
        $modelo::create($this->validar($request));

        return redirect()->route($this->rota().'.index')->with('status', $this->rotulos()['singular'].' cadastrad'.$this->genero().'.');
    }

    public function edit(Request $request): View
    {
        return $this->formulario($this->encontrar($request));
    }

    public function update(Request $request): RedirectResponse
    {
        $item = $this->encontrar($request);
        $item->update($this->validar($request, $item));

        return redirect()->route($this->rota().'.index')->with('status', $this->rotulos()['singular'].' atualizad'.$this->genero().'.');
    }

    private function encontrar(Request $request): Model
    {
        $modelo = $this->modelo();

        return $modelo::findOrFail($request->route('id'));
    }

    private function formulario(Model $item): View
    {
        return view('tenant.cadastros-simples.form', [
            'item' => $item,
            'rota' => $this->rota(),
            'rotulos' => $this->rotulos(),
        ]);
    }

    private function validar(Request $request, ?Model $item = null): array
    {
        $tabela = (new ($this->modelo()))->getTable();

        $dados = $request->validate([
            'nome' => ['required', 'string', 'max:80', Rule::unique($tabela, 'nome')->ignore($item?->getKey())],
        ]);
        $dados['ativo'] = $request->boolean('ativo');

        return $dados;
    }

    private function genero(): string
    {
        return $this->rotulos()['feminino'] ? 'a' : 'o';
    }
}
