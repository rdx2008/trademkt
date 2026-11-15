<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\Perfil;
use App\Http\Controllers\Controller;
use App\Models\Documento;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Meus documentos: arquivos da empresa com permissão por perfil. Só o admin da instalação envia e
 * remove; os demais veem (e baixam) o que o perfil deles pode. Arquivos privados, por rota autenticada.
 */
class DocumentoController extends Controller
{
    private function gestor(Request $request): bool
    {
        return $request->user()->temPerfil(Perfil::AdminInstalacao);
    }

    public function index(Request $request): View
    {
        return view('tenant.documentos.index', [
            'documentos' => Documento::visiveisPara($request->user())->orderBy('categoria')->orderBy('titulo')->get(),
            'podeGerir' => $this->gestor($request),
            'categorias' => Documento::CATEGORIAS,
            'perfis' => Documento::PERFIS_LEITORES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->gestor($request), 403);
        $dados = $request->validate([
            'titulo' => ['required', 'string', 'max:150'],
            'descricao' => ['nullable', 'string', 'max:500'],
            'categoria' => ['nullable', Rule::in(array_keys(Documento::CATEGORIAS))],
            'arquivo' => ['required', 'file', 'max:20480', 'extensions:'.implode(',', Documento::EXTENSOES)],
            'perfis' => ['nullable', 'array'],
            'perfis.*' => [Rule::in(array_keys(Documento::PERFIS_LEITORES))],
        ], ['arquivo.extensions' => 'Tipo de arquivo não aceito.', 'arquivo.max' => 'O arquivo pode ter até 20 MB.']);

        $arquivo = $request->file('arquivo');
        $caminho = 'documentos/'.Str::uuid().'.'.strtolower($arquivo->getClientOriginalExtension());
        Storage::disk('local')->putFileAs(dirname($caminho), $arquivo, basename($caminho));
        Documento::create([
            'titulo' => $dados['titulo'], 'descricao' => $dados['descricao'] ?? null, 'categoria' => $dados['categoria'] ?? 'outro',
            'arquivo' => $caminho, 'nome_original' => Str::limit($arquivo->getClientOriginalName(), 190, ''),
            'mime' => $arquivo->getClientMimeType(), 'tamanho' => $arquivo->getSize(),
            'perfis' => array_values($dados['perfis'] ?? []) ?: null,
            'enviado_por' => $request->user()->id, 'enviado_por_nome' => $request->user()->nome,
        ]);

        return redirect()->route('documentos.index')->with('status', 'Documento enviado.');
    }

    public function baixar(Request $request, int $documento): StreamedResponse
    {
        $d = Documento::visiveisPara($request->user())->findOrFail($documento);
        abort_unless(Storage::disk('local')->exists($d->arquivo), 404);

        return Storage::disk('local')->download($d->arquivo, $d->nome_original, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function destroy(Request $request, int $documento): RedirectResponse
    {
        abort_unless($this->gestor($request), 403);
        $d = Documento::findOrFail($documento);
        Storage::disk('local')->delete($d->arquivo);
        $d->delete();

        return redirect()->route('documentos.index')->with('status', 'Documento removido.');
    }
}
