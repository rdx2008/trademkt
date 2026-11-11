<?php

namespace App\Http\Controllers;

use App\Models\SiteContato;
use App\Services\ConteudoInstitucional;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class InstitucionalController extends Controller
{
    public function index(ConteudoInstitucional $conteudo)
    {
        return response()->view('institucional.index', ['site' => $conteudo->publico(), 'previa' => false]);
    }

    public function privacidade(ConteudoInstitucional $conteudo)
    {
        return view('institucional.privacidade', ['site' => $conteudo->publico()]);
    }

    public function arquivo(string $arquivo)
    {
        abort_unless(preg_match('/^[a-f0-9-]{36}\.(png|jpg|webp)$/D', $arquivo), 404);
        $disk = Storage::disk('local');
        abort_unless($disk->exists('institucional/'.$arquivo), 404);

        return response()->file($disk->path('institucional/'.$arquivo), [
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function contato(Request $request, ConteudoInstitucional $conteudo)
    {
        abort_unless($conteudo->publico()['secoes']['contato']['visivel'] ?? false, 404);
        $dados = $request->validate([
            'nome' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'empresa' => ['required', 'string', 'max:160'],
            'perfil' => ['required', 'in:industria,agencia,outro'],
            'mensagem' => ['required', 'string', 'max:5000'],
            'privacidade' => ['accepted'],
            'website' => ['nullable', 'string', 'max:0'],
        ]);
        SiteContato::create(collect($dados)->only(['nome', 'email', 'empresa', 'perfil', 'mensagem'])->all());

        return redirect()->to(route('institucional.home').'#contato')->with('contato_enviado', true);
    }
}
