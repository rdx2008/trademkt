<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\Perfil;
use App\Enums\TipoInstalacao;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Crachá digital do promotor. A gestão da agência imprime o crachá (foto + QR); quem lê o QR cai
 * numa página pública que confirma foto, primeiro nome, agência e se ele está ativo. Nunca mostra
 * CPF, e-mail, telefone nem onde o promotor está. O código do QR é a credencial da página.
 */
class CrachaController extends Controller
{
    /** Página para imprimir, só para quem consulta a equipe. */
    public function mostrar(Request $request, int $promotor): View
    {
        $eu = $request->user();
        abort_unless(tenant()->tipo === TipoInstalacao::Agencia && $eu->podeVerPromotores(), 403);
        $usuario = User::where('perfil', Perfil::Promotor->value)->findOrFail($promotor);

        return view('tenant.promotores.cracha', [
            'p' => $usuario,
            'url' => route('cracha.verificar', self::codigo($usuario)),
        ]);
    }

    /** Código público do crachá, criado na primeira vez que é pedido. */
    public static function codigo(User $usuario): string
    {
        if (! $usuario->cracha_token) {
            $usuario->forceFill(['cracha_token' => Str::random(40)])->save();
        }

        return $usuario->cracha_token;
    }

    private function promotorDoCodigo(string $codigo): ?User
    {
        return User::where('cracha_token', $codigo)->where('perfil', Perfil::Promotor->value)->first();
    }

    public function verificar(string $codigo): Response
    {
        $usuario = tenant()->tipo === TipoInstalacao::Agencia ? $this->promotorDoCodigo($codigo) : null;
        if (! $usuario) {
            return response()->view('tenant.cracha.invalido', [], 404)->withHeaders($this->cabecalhos());
        }

        return response()->view('tenant.cracha.verificar', [
            'p' => $usuario,
            'codigo' => $codigo,
        ])->withHeaders($this->cabecalhos());
    }

    public function foto(string $codigo): StreamedResponse|Response
    {
        $usuario = tenant()->tipo === TipoInstalacao::Agencia ? $this->promotorDoCodigo($codigo) : null;
        if (! $usuario || ! $usuario->ativo || ! $usuario->foto || ! Storage::disk('local')->exists($usuario->foto)) {
            abort(404);
        }

        return Storage::disk('local')->response($usuario->foto, headers: ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'no-referrer']);
    }

    private function cabecalhos(): array
    {
        return ['Referrer-Policy' => 'no-referrer', 'Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex, nofollow'];
    }
}
