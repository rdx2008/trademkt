<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Convite;
use App\Services\Promotores\Cadastro;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Primeiro acesso do promotor: ele abre o link do convite, lê o aviso de privacidade e cria a
 * própria senha. Página pública (o token é a credencial), sem referenciador e sem cache.
 */
class ConviteController extends Controller
{
    public function __construct(private Cadastro $cadastro) {}

    private function valido(string $token): ?Convite
    {
        $convite = $this->cadastro->conviteDoToken($token);

        return $convite && $convite->valido() && $convite->usuario?->ativo ? $convite : null;
    }

    public function mostrar(string $token): View|Response
    {
        $convite = $this->valido($token);
        if (! $convite) {
            return response()->view('tenant.convite.invalido', [], 410)->withHeaders($this->cabecalhos());
        }

        return response()->view('tenant.convite.aceitar', ['convite' => $convite, 'token' => $token])->withHeaders($this->cabecalhos());
    }

    public function aceitar(Request $request, string $token): RedirectResponse|Response
    {
        $convite = $this->valido($token);
        if (! $convite) {
            return response()->view('tenant.convite.invalido', [], 410)->withHeaders($this->cabecalhos());
        }
        $request->validate([
            'password' => ['required', 'confirmed', Password::defaults()],
            'aceite' => ['accepted'],
        ], [
            'aceite.accepted' => 'Marque que leu o aviso de privacidade para continuar.',
        ], ['password' => 'senha']);
        $this->cadastro->aceitarConvite($convite, $request->string('password')->toString());

        return redirect()->route('convite.pronto')->withHeaders($this->cabecalhos());
    }

    public function pronto(): View
    {
        return view('tenant.convite.pronto');
    }

    /** @return array<string, string> */
    private function cabecalhos(): array
    {
        return ['Referrer-Policy' => 'no-referrer', 'Cache-Control' => 'no-store, private'];
    }
}
