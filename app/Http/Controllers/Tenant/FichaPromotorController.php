<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\Perfil;
use App\Enums\TipoInstalacao;
use App\Http\Controllers\Controller;
use App\Models\Afastamento;
use App\Models\Ocorrencia;
use App\Models\User;
use App\Services\Promotores\Cadastro;
use App\Services\Promotores\FichaOperacional;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Ficha do promotor: agenda e histórico nas indústrias atendidas, afastamentos, ocorrências e
 * aparelhos conectados. Admin e supervisor consultam; só o admin registra e altera.
 */
class FichaPromotorController extends Controller
{
    public function __construct(private Cadastro $cadastro, private FichaOperacional $ficha) {}

    private function consultar(Request $request): User
    {
        $eu = $request->user();
        abort_unless(tenant()->tipo === TipoInstalacao::Agencia && $eu->podeVerPromotores(), 403);

        return $eu;
    }

    private function gerir(Request $request): User
    {
        $eu = $this->consultar($request);
        abort_unless($eu->podeGerirPromotores(), 403);

        return $eu;
    }

    private function promotor(int $id): User
    {
        return User::where('perfil', Perfil::Promotor->value)->findOrFail($id);
    }

    public function show(Request $request, int $promotor): View
    {
        $eu = $this->consultar($request);
        $usuario = $this->promotor($promotor);

        return view('tenant.promotores.ficha', [
            'p' => $usuario,
            'podeGerir' => $eu->podeGerirPromotores(),
            'operacional' => $this->ficha->de(tenant(), $usuario->id),
            'afastamentos' => $usuario->afastamentos()->limit(20)->get(),
            'afastadoHoje' => $usuario->afastamentoEm(),
            'ocorrencias' => $usuario->ocorrencias()->limit(30)->get(),
            'aparelhos' => $usuario->tokens()->orderByDesc('last_used_at')->orderByDesc('id')->get(),
            'tiposAfastamento' => Afastamento::TIPOS,
            'tiposOcorrencia' => Ocorrencia::TIPOS,
        ]);
    }

    public function afastar(Request $request, int $promotor): RedirectResponse
    {
        $eu = $this->gerir($request);
        $usuario = $this->promotor($promotor);
        $dados = $request->validate([
            'tipo' => ['required', Rule::in(array_keys(Afastamento::TIPOS))],
            'inicio' => ['required', 'date'],
            'fim' => ['required', 'date', 'after_or_equal:inicio'],
            'observacao' => ['nullable', 'string', 'max:300'],
        ]);
        $usuario->afastamentos()->create($dados + ['criado_por' => $eu->id]);
        $this->cadastro->auditar($usuario, 'afastamento_registrado', $eu, ['tipo' => $dados['tipo'], 'inicio' => $dados['inicio'], 'fim' => $dados['fim']]);

        return redirect()->route('promotores.ficha', $usuario)->with('status', 'Afastamento registrado.');
    }

    public function encerrarAfastamento(Request $request, int $promotor, int $afastamento): RedirectResponse
    {
        $eu = $this->gerir($request);
        $usuario = $this->promotor($promotor);
        $registro = $usuario->afastamentos()->findOrFail($afastamento);
        $registro->delete();
        $this->cadastro->auditar($usuario, 'afastamento_removido', $eu, ['tipo' => $registro->tipo]);

        return redirect()->route('promotores.ficha', $usuario)->with('status', 'Afastamento removido.');
    }

    public function ocorrencia(Request $request, int $promotor): RedirectResponse
    {
        $eu = $this->gerir($request);
        $usuario = $this->promotor($promotor);
        $dados = $request->validate([
            'tipo' => ['required', Rule::in(array_keys(Ocorrencia::TIPOS))],
            'data' => ['required', 'date', 'before_or_equal:today'],
            'descricao' => ['required', 'string', 'max:2000'],
        ]);
        $usuario->ocorrencias()->create($dados + ['registrado_por' => $eu->id]);
        $this->cadastro->auditar($usuario, 'ocorrencia_registrada', $eu, ['tipo' => $dados['tipo']]);

        return redirect()->route('promotores.ficha', $usuario)->with('status', 'Ocorrência registrada.');
    }

    public function revogarAparelho(Request $request, int $promotor, int $token): RedirectResponse
    {
        $eu = $this->gerir($request);
        $usuario = $this->promotor($promotor);
        $aparelho = $usuario->tokens()->findOrFail($token);
        $aparelho->delete();
        $this->cadastro->auditar($usuario, 'aparelho_desconectado', $eu, ['aparelho' => $aparelho->name]);

        return redirect()->route('promotores.ficha', $usuario)->with('status', 'Aparelho desconectado. O promotor precisa entrar de novo no app.');
    }
}
