<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\Perfil;
use App\Http\Controllers\Controller;
use App\Services\Marca\Aparencia;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Configurações → Aparência: marca própria (nome, cor e logos) da instalação.
 * Só o admin da instalação edita, e só se o fornecedor não tiver desligado o recurso.
 */
class AparenciaController extends Controller
{
    public function edit(Request $request): View
    {
        $this->liberar($request);

        return view('tenant.configuracoes.aparencia', ['aparencia' => Aparencia::atual()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->liberar($request);

        $dados = $request->validate([
            'nome' => ['nullable', 'string', 'max:60'],
            'cor' => ['nullable', 'string', 'max:7'],
            'logo_claro' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'logo_escuro' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'icone' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'remover' => ['nullable', 'array'],
            'remover.*' => [Rule::in(array_keys(Aparencia::ARQUIVOS))],
        ], [
            'logo_claro.mimes' => 'O logo deve ser PNG, JPG ou WebP.',
            'logo_escuro.mimes' => 'O logo deve ser PNG, JPG ou WebP.',
            'icone.mimes' => 'O ícone deve ser PNG, JPG ou WebP.',
            'logo_claro.max' => 'O logo pode ter no máximo 2 MB.',
            'logo_escuro.max' => 'O logo pode ter no máximo 2 MB.',
            'icone.max' => 'O ícone pode ter no máximo 2 MB.',
        ]);

        $arquivos = array_filter([
            'claro' => $request->file('logo_claro'),
            'escuro' => $request->file('logo_escuro'),
            'icone' => $request->file('icone'),
        ]);

        Aparencia::atual()->salvar(
            ['nome' => $dados['nome'] ?? null, 'cor' => $dados['cor'] ?? null],
            $arquivos,
            $dados['remover'] ?? [],
        );

        return redirect()->route('configuracoes.aparencia')->with('status', 'Aparência atualizada.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $this->liberar($request);
        Aparencia::atual()->restaurar();

        return redirect()->route('configuracoes.aparencia')->with('status', 'Aparência restaurada para o padrão.');
    }

    private function liberar(Request $request): void
    {
        abort_unless($request->user()->temPerfil(Perfil::AdminInstalacao), 403);
        abort_unless(tenant()->whiteLabelAtivo(), 403, 'A marca própria não está liberada para esta instalação.');
    }
}
