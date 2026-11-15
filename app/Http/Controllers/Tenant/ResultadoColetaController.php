<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Pdv;
use App\Models\Rede;
use App\Services\Coletas\ResultadosColeta;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Resultados das coletas (fase 10): preço, validade, estoque e share, com filtros e CSV. Cada perfil
 * vê só as visitas que já pode ver (indústria, carteira, agência).
 */
class ResultadoColetaController extends Controller
{
    public function __construct(private ResultadosColeta $resultados) {}

    /** @return array{inicio: string, fim: string, rede_id: ?int, cidade: ?string, dias: int} */
    private function filtros(Request $request): array
    {
        $d = $request->validate([
            'inicio' => ['nullable', 'date_format:Y-m-d'],
            'fim' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:inicio'],
            'rede_id' => ['nullable', 'integer'],
            'cidade' => ['nullable', 'string', 'max:80'],
            'dias' => ['nullable', 'integer', 'between:1,365'],
        ]);

        return [
            'inicio' => $d['inicio'] ?? Carbon::today()->subDays(29)->toDateString(),
            'fim' => $d['fim'] ?? Carbon::today()->toDateString(),
            'rede_id' => $d['rede_id'] ?? null,
            'cidade' => $d['cidade'] ?? null,
            'dias' => (int) ($d['dias'] ?? 60),
        ];
    }

    private function tipo(Request $request): string
    {
        return (string) $request->validate(['tipo' => ['nullable', Rule::in(array_keys(ResultadosColeta::TIPOS))]])['tipo'] ?? 'preco';
    }

    public function index(Request $request)
    {
        $tipo = $this->tipo($request);
        $f = $this->filtros($request);

        return view('tenant.coletas.resultados', [
            'tipo' => $tipo,
            'tipos' => ResultadosColeta::TIPOS,
            'filtros' => $f,
            'relatorio' => $this->resultados->relatorio($tipo, $request->user(), $f),
            'redes' => Rede::orderBy('nome')->pluck('nome', 'id'),
            'cidades' => Pdv::whereNotNull('cidade')->distinct()->orderBy('cidade')->pluck('cidade'),
        ]);
    }

    /** CSV do relatório na tela, com textos protegidos contra fórmulas. */
    public function csv(Request $request): StreamedResponse
    {
        $tipo = $this->tipo($request);
        $relatorio = $this->resultados->relatorio($tipo, $request->user(), $this->filtros($request));
        $texto = fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'".$v : $v;

        return response()->streamDownload(function () use ($relatorio, $texto) {
            $saida = fopen('php://output', 'w');
            fwrite($saida, "\xEF\xBB\xBF");
            fputcsv($saida, $relatorio['colunas'], ';');
            foreach ($relatorio['linhas'] as $linha) {
                fputcsv($saida, array_map($texto, $linha), ';');
            }
            fclose($saida);
        }, 'coletas-'.$tipo.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
