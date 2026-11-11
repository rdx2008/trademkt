<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Marca\Aparencia;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Throwable;

class EmpresaController extends Controller
{
    /**
     * Descoberta pelo app: código da empresa => URL da instalação.
     * Não expõe nada além do necessário para o app se conectar e se vestir com a marca do cliente.
     */
    public function show(string $codigo): JsonResponse
    {
        $tenant = Tenant::where('codigo', Str::lower(trim($codigo)))->first();

        if (! $tenant || ! $tenant->ativo || ! $tenant->url()) {
            return response()->json(['message' => 'Empresa não encontrada.'], 404);
        }

        return response()->json([
            'codigo' => $tenant->codigo,
            'nome' => $tenant->nome,
            'url' => $tenant->url(),
            'api' => $tenant->url().'/api/v1',
            'api_versao' => config('trade.api_versao'),
            'marca' => $this->marca($tenant),
        ]);
    }

    /**
     * Marca própria da instalação para o app: nome exibido, cor principal (`#rrggbb`, ou null
     * para o visual padrão) e URLs públicas do logo e do ícone. Com a marca própria desligada
     * pelo fornecedor, só o nome do cliente vai.
     *
     * @return array{nome: string, cor: ?string, logo_url: ?string, icone_url: ?string}
     */
    private function marca(Tenant $tenant): array
    {
        $padrao = ['nome' => $tenant->nome, 'cor' => null, 'logo_url' => null, 'icone_url' => null];

        if (! $tenant->whiteLabelAtivo()) {
            return $padrao;
        }

        try {
            return $tenant->run(function () use ($tenant) {
                Aparencia::esquecer();
                $aparencia = Aparencia::atual();
                $arquivo = fn (string $tipo) => $aparencia->temArquivo($tipo)
                    ? $tenant->url().'/marca/'.$tipo.'?v='.$aparencia->versao()
                    : null;

                return [
                    'nome' => $aparencia->nome(),
                    'cor' => $aparencia->temCorPropria() ? $aparencia->cor() : null,
                    'logo_url' => $arquivo('claro') ?? $arquivo('escuro'),
                    'icone_url' => $arquivo('icone'),
                ];
            });
        } catch (Throwable $e) {
            report($e);

            return $padrao;
        }
    }
}
