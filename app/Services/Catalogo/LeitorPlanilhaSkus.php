<?php

namespace App\Services\Catalogo;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenSpout\Reader\CSV\Options as OpcoesCsv;
use OpenSpout\Reader\CSV\Reader as LeitorCsv;
use OpenSpout\Reader\XLSX\Reader as LeitorXlsx;

/**
 * Lê planilhas (CSV ou XLSX) de produtos. Aceita dois formatos, que podem ser misturados:
 *
 * - largo: uma linha por produto, com uma coluna de GTIN por embalagem
 *   (GTIN unidade, GTIN reembalagem, GTIN caixa master e suas quantidades);
 * - longo: uma linha por embalagem, repetindo o código do produto (colunas Tipo, Quantidade, GTIN).
 *
 * Linhas com o mesmo código são unidas depois, em ImportacaoSkus.
 */
class LeitorPlanilhaSkus
{
    /** Cabeçalhos aceitos (normalizados) → campo. */
    private const COLUNAS = [
        'codigo' => 'codigo', 'cod' => 'codigo', 'sku' => 'codigo', 'codigo_do_produto' => 'codigo',
        'codigo_produto' => 'codigo', 'cod_produto' => 'codigo', 'codigo_sku' => 'codigo', 'referencia' => 'codigo',
        'descricao' => 'descricao', 'produto' => 'descricao', 'nome' => 'descricao', 'nome_do_produto' => 'descricao',
        'descricao_do_produto' => 'descricao', 'descricao_produto' => 'descricao',
        'categoria' => 'categoria', 'grupo' => 'categoria', 'familia' => 'categoria',
        // Formato longo
        'tipo' => 'tipo', 'tipo_embalagem' => 'tipo', 'tipo_de_embalagem' => 'tipo',
        'quantidade' => 'quantidade', 'qtd' => 'quantidade', 'qtde' => 'quantidade',
        'gtin' => 'gtin', 'ean' => 'gtin', 'codigo_de_barras' => 'gtin', 'cod_barras' => 'gtin',
        'codigo_embalagem' => 'codigo_embalagem', 'cod_embalagem' => 'codigo_embalagem',
        // Formato largo
        'gtin_unidade' => 'gtin_unidade', 'gtin_unitario' => 'gtin_unidade', 'ean_unidade' => 'gtin_unidade',
        'gtin13' => 'gtin_unidade', 'ean13' => 'gtin_unidade', 'gtin_13' => 'gtin_unidade',
        'gtin_reembalagem' => 'gtin_reembalagem', 'ean_reembalagem' => 'gtin_reembalagem',
        'gtin_caixa_master' => 'gtin_caixa_master', 'gtin_caixa' => 'gtin_caixa_master', 'gtin_master' => 'gtin_caixa_master',
        'ean_caixa' => 'gtin_caixa_master', 'ean_caixa_master' => 'gtin_caixa_master',
        'qtd_unidade' => 'qtd_unidade', 'quantidade_unidade' => 'qtd_unidade',
        'qtd_reembalagem' => 'qtd_reembalagem', 'quantidade_reembalagem' => 'qtd_reembalagem',
        'qtd_caixa_master' => 'qtd_caixa_master', 'qtd_caixa' => 'qtd_caixa_master', 'quantidade_caixa_master' => 'qtd_caixa_master',
        'qtd_master' => 'qtd_caixa_master',
        'codigo_reembalagem' => 'cod_reembalagem', 'cod_reembalagem' => 'cod_reembalagem',
        'codigo_caixa_master' => 'cod_caixa_master', 'cod_caixa_master' => 'cod_caixa_master', 'codigo_caixa' => 'cod_caixa_master',
    ];

    /** Texto da planilha → tipo de embalagem. */
    private const TIPOS = [
        'unidade' => 'unidade', 'un' => 'unidade', 'unitario' => 'unidade', 'unit' => 'unidade', 'individual' => 'unidade',
        'reembalagem' => 'reembalagem', 'pacote' => 'reembalagem', 'display' => 'reembalagem',
        'caixa_master' => 'caixa_master', 'caixa' => 'caixa_master', 'master' => 'caixa_master', 'cx' => 'caixa_master',
        'pacote_master' => 'caixa_master',
    ];

    /**
     * @return list<array{linha: int, origem: string, dados: array<string, mixed>, avisos: list<string>}>
     *
     * @throws ValidationException
     */
    public function ler(string $caminho, string $extensao): array
    {
        $leitor = strtolower($extensao) === 'xlsx' ? new LeitorXlsx : new LeitorCsv($this->opcoesCsv($caminho));
        $leitor->open($caminho);

        $mapa = null;
        $itens = [];
        $maximo = (int) config('trade.importacao_skus.max_linhas');

        try {
            foreach ($leitor->getSheetIterator() as $planilha) {
                $numero = 0;
                foreach ($planilha->getRowIterator() as $linha) {
                    $numero++;
                    $celulas = $linha->toArray();

                    if ($mapa === null) {
                        $mapa = $this->mapearCabecalho($celulas);

                        continue;
                    }

                    $campos = [];
                    foreach ($mapa as $indice => $campo) {
                        $campos[$campo] = $celulas[$indice] ?? null;
                    }

                    if (collect($campos)->filter(fn ($v) => trim((string) $v) !== '')->isEmpty()) {
                        continue;
                    }

                    if (count($itens) >= $maximo) {
                        throw ValidationException::withMessages([
                            'arquivo' => "A planilha passa de {$maximo} linhas. Divida em arquivos menores.",
                        ]);
                    }

                    $avisos = [];
                    $itens[] = [
                        'linha' => $numero,
                        'origem' => "Linha {$numero}",
                        'dados' => [
                            'codigo' => $this->texto($campos['codigo'] ?? null),
                            'descricao' => $this->texto($campos['descricao'] ?? null),
                            'categoria' => $this->texto($campos['categoria'] ?? null) ?: null,
                            'embalagens' => $this->embalagens($campos, $avisos),
                        ],
                        'avisos' => $avisos,
                    ];
                }

                break; // só a primeira aba
            }
        } finally {
            $leitor->close();
        }

        if ($mapa === null || $itens === []) {
            throw ValidationException::withMessages(['arquivo' => 'A planilha está vazia.']);
        }

        return $itens;
    }

    /** @return array<int, string> índice da coluna → campo */
    private function mapearCabecalho(array $celulas): array
    {
        $mapa = [];
        foreach ($celulas as $indice => $titulo) {
            $chave = Str::of((string) $titulo)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->toString();
            $campo = self::COLUNAS[$chave] ?? null;

            if ($campo && ! in_array($campo, $mapa, true)) {
                $mapa[$indice] = $campo;
            }
        }

        $faltando = array_diff(['codigo', 'descricao'], $mapa);
        if ($faltando) {
            throw ValidationException::withMessages([
                'arquivo' => 'Colunas obrigatórias não encontradas na primeira linha: '.implode(', ', $faltando).'. Baixe o modelo da planilha.',
            ]);
        }

        return $mapa;
    }

    /**
     * @param  array<string, mixed>  $campos
     * @param  list<string>  $avisos
     * @return list<array<string, mixed>>
     */
    private function embalagens(array $campos, array &$avisos): array
    {
        $embalagens = [];

        // Formato largo: uma coluna de GTIN por tipo.
        foreach ([
            'unidade' => ['gtin_unidade', 'qtd_unidade', null],
            'reembalagem' => ['gtin_reembalagem', 'qtd_reembalagem', 'cod_reembalagem'],
            'caixa_master' => ['gtin_caixa_master', 'qtd_caixa_master', 'cod_caixa_master'],
        ] as $tipo => [$colunaGtin, $colunaQtd, $colunaCodigo]) {
            $gtin = $this->gtin($campos[$colunaGtin] ?? null);
            $quantidade = $this->quantidade($campos[$colunaQtd] ?? null);
            $codigo = $colunaCodigo ? $this->texto($campos[$colunaCodigo] ?? null) : '';

            if ($gtin === '' && $quantidade === null && $codigo === '') {
                continue;
            }

            $embalagens[] = [
                'tipo' => $tipo,
                'quantidade' => $quantidade ?? 1,
                'quantidade_conhecida' => $quantidade !== null || $tipo === 'unidade',
                'gtin' => $gtin,
                'codigo' => $codigo ?: null,
            ];
        }

        // Formato longo: tipo, quantidade e GTIN na própria linha.
        $gtinLongo = $this->gtin($campos['gtin'] ?? null);
        $tipoTexto = $this->texto($campos['tipo'] ?? null);
        if ($gtinLongo !== '' || $tipoTexto !== '') {
            $chave = Str::of($tipoTexto)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->toString();
            $tipo = $tipoTexto === '' ? 'unidade' : (self::TIPOS[$chave] ?? null);
            $quantidade = $this->quantidade($campos['quantidade'] ?? null);

            $embalagens[] = [
                'tipo' => $tipo ?? $tipoTexto,
                'quantidade' => $quantidade ?? 1,
                'quantidade_conhecida' => $quantidade !== null || $tipo === 'unidade',
                'gtin' => $gtinLongo,
                'codigo' => ($c = $this->texto($campos['codigo_embalagem'] ?? null)) !== '' ? $c : null,
            ];
        }

        return $embalagens;
    }

    private function texto(mixed $valor): string
    {
        if (is_float($valor) && floor($valor) === $valor) {
            return sprintf('%.0f', $valor);
        }

        return trim((string) $valor);
    }

    /** GTIN lido como número (Excel) vira texto de dígitos, sem notação científica. */
    private function gtin(mixed $valor): string
    {
        if (is_int($valor) || is_float($valor)) {
            return sprintf('%.0f', $valor);
        }

        $texto = trim((string) $valor);
        if ($texto !== '' && preg_match('/^\d+(?:[.,]\d+)?[eE]\+?\d+$/', $texto)) {
            return sprintf('%.0f', (float) str_replace(',', '.', $texto));
        }

        return preg_replace('/[\s.\-]/', '', $texto);
    }

    /** "25", 25, "25 UN" e "10.000" viram inteiros; vazio ou sem número vira null. */
    private function quantidade(mixed $valor): ?int
    {
        if (is_int($valor) || is_float($valor)) {
            return $valor >= 1 ? (int) $valor : null;
        }

        if (! preg_match('/\d{1,3}(?:\.\d{3})+|\d+/', (string) $valor, $m)) {
            return null;
        }

        $numero = (int) str_replace('.', '', $m[0]);

        return $numero >= 1 ? $numero : null;
    }

    private function opcoesCsv(string $caminho): OpcoesCsv
    {
        $inicio = (string) file_get_contents($caminho, false, null, 0, 8192);
        // Corta na última quebra de linha para não partir um caractere multibyte.
        if (($fim = strrpos($inicio, "\n")) !== false) {
            $inicio = substr($inicio, 0, $fim);
        }
        $primeiraLinha = strtok($inicio, "\n") ?: '';

        $opcoes = new OpcoesCsv;
        $opcoes->FIELD_DELIMITER = substr_count($primeiraLinha, ';') > substr_count($primeiraLinha, ',') ? ';' : ',';
        // Excel no Brasil costuma salvar CSV em Windows-1252.
        $opcoes->ENCODING = mb_check_encoding($inicio, 'UTF-8') ? 'UTF-8' : 'Windows-1252';

        return $opcoes;
    }
}
