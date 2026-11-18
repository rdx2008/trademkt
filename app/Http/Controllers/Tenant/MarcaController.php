<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\Marca\Aparencia;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Arquivos públicos da marca da instalação (folha de estilo e logos). Públicos porque a tela
 * de entrada precisa deles antes do login; o endereço leva ?v=<versão> e muda a cada alteração.
 */
class MarcaController extends Controller
{
    public function css(Request $request): Response
    {
        $aparencia = Aparencia::atual();

        return response($aparencia->css(), 200, [
            'Content-Type' => 'text/css; charset=UTF-8',
            'Cache-Control' => $this->cache($request, $aparencia),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function arquivo(Request $request, string $tipo): StreamedResponse
    {
        $aparencia = Aparencia::atual();
        $caminho = $aparencia->caminho($tipo);
        abort_unless($caminho && Storage::disk('local')->exists($caminho), 404);

        return Storage::disk('local')->response($caminho, headers: [
            'Content-Type' => 'image/png',
            'Cache-Control' => $this->cache($request, $aparencia),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Cache longo só quando o endereço traz a versão vigente; senão, sempre revalidar. */
    private function cache(Request $request, Aparencia $aparencia): string
    {
        return $request->query('v') === $aparencia->versao()
            ? 'public, max-age=31536000, immutable'
            : 'public, max-age=0, must-revalidate';
    }
}
