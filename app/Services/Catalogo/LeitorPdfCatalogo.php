<?php

namespace App\Services\Catalogo;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;

/**
 * Lê catálogos em PDF com texto selecionável, sem inteligência artificial.
 *
 * O PDF é reduzido a linhas de texto e cada linha é classificada: GTIN (12 a 14 dígitos,
 * com ou sem marcador de variação), código de produto, título (maiúsculas) ou descrição de
 * embalagem. Um produto é o conjunto de GTINs e códigos entre dois títulos; GTIN depois de
 * um código começa outro produto sob o mesmo título.
 *
 * Os catálogos de fabricantes têm layouts diferentes. Este leitor foi calibrado com o
 * catálogo da Gaboardi (códigos como 10FL01005); tudo que ele não tem certeza vira aviso e
 * passa pela tela de revisão antes de qualquer gravação.
 *
 * Cada item devolvido tem: linha, origem, dados (codigo, descricao, categoria, embalagens)
 * e avisos.
 */
class LeitorPdfCatalogo
{
    /** Código do produto no padrão do catálogo: 2 dígitos, 2 ou 3 letras e 4 ou 5 dígitos (10FL01005, 20LCA1001). */
    private const PADRAO_CODIGO = '/^\d{2}[A-Z]{2,3}\d{4,5}$/u';

    private const PADRAO_GTIN = '/^([•\-\*·–])?\s*(\d{12,14})$/u';

    private const PADRAO_MARCADOR = '/^([•\-\*·–])\s*(.+)$/u';

    private const PADRAO_RODAPE = '/^\d{0,3}\s*Cat\s*[áa]\s*logo\s+de\s+Produtos/iu';

    /**
     * @return list<array{linha: int, origem: string, dados: array<string, mixed>, avisos: list<string>}>
     *
     * @throws ValidationException
     */
    public function ler(string $caminho): array
    {
        try {
            $config = new Config;
            $config->setRetainImageContent(false);
            $pdf = (new Parser([], $config))->parseFile($caminho);

            $paginas = [];
            foreach ($pdf->getPages() as $indice => $pagina) {
                $paginas[$indice + 1] = $pagina->getText();
            }
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'arquivo' => 'Não foi possível ler o PDF. Ele pode estar protegido por senha ou danificado.',
            ]);
        }

        if (mb_strlen(trim(implode('', $paginas))) < 20) {
            throw ValidationException::withMessages([
                'arquivo' => 'Este PDF não tem texto selecionável (parece escaneado). Use a planilha de produtos.',
            ]);
        }

        $itens = $this->interpretar($paginas);

        if ($itens === []) {
            throw ValidationException::withMessages([
                'arquivo' => 'Nenhum produto foi reconhecido neste PDF. O layout pode ser diferente do esperado; use a planilha de produtos.',
            ]);
        }

        return $itens;
    }

    /**
     * Interpreta o texto já extraído, uma entrada por página.
     *
     * @param  array<int, string>  $paginas  número da página => texto
     * @return list<array{linha: int, origem: string, dados: array<string, mixed>, avisos: list<string>}>
     */
    public function interpretar(array $paginas): array
    {
        $registros = $this->separarRegistros($paginas);

        return $this->montarItens($registros);
    }

    /**
     * @param  array<int, string>  $paginas
     * @return list<array<string, mixed>>
     */
    private function separarRegistros(array $paginas): array
    {
        $registros = [];
        $titulo = null;
        $tituloId = 0;
        $cabecalhos = [];
        $categorias = [];
        $anteriorEraTitulo = false;
        $registro = $this->registroVazio(1);

        $fechar = function () use (&$registro, &$registros, &$titulo, &$tituloId, &$cabecalhos, &$categorias) {
            if ($registro['gtins'] !== [] || $registro['codigos'] !== []) {
                $registro['titulo'] = $titulo;
                $registro['titulo_id'] = $tituloId;
                $registro['categoria'] = $this->categoriaDe($titulo, $cabecalhos, $categorias);
                $registros[] = $registro;
            }
            $registro = $this->registroVazio($registro['pagina']);
        };

        foreach ($paginas as $numero => $texto) {
            foreach ($this->linhasDaPagina((string) $texto) as $linha) {
                $registro['pagina'] = $registro['gtins'] === [] && $registro['codigos'] === [] ? $numero : $registro['pagina'];

                if (preg_match(self::PADRAO_GTIN, $linha, $m)) {
                    if ($registro['codigos'] !== []) {
                        $fechar();
                        $registro['pagina'] = $numero;
                    }
                    $registro['gtins'][] = ['marcador' => $m[1] ?? '', 'digitos' => $m[2]];
                    $anteriorEraTitulo = false;
                } elseif (preg_match(self::PADRAO_CODIGO, $linha)) {
                    $registro['codigos'][] = $linha;
                    $anteriorEraTitulo = false;
                } elseif ($this->ehTitulo($linha)) {
                    $fechar();
                    $cabecalhos = $anteriorEraTitulo && $titulo !== null ? [...$cabecalhos, $titulo] : [];
                    $titulo = $linha;
                    $tituloId++;
                    $registro['pagina'] = $numero;
                    $anteriorEraTitulo = true;
                } else {
                    if ($registro['codigos'] !== [] && ($this->ehDescricao($linha) || preg_match(self::PADRAO_MARCADOR, $linha))) {
                        $fechar();
                        $registro['pagina'] = $numero;
                    }
                    if (preg_match(self::PADRAO_MARCADOR, $linha, $m)) {
                        // O primeiro texto de cada marcador nomeia a variação (ex.: "• Ø 3,0 x 180 mm").
                        if (isset($registro['variantes'][$m[1]])) {
                            $registro['variantes_extra'][$m[1]] ??= trim($m[2]);
                        } else {
                            $registro['variantes'][$m[1]] = trim($m[2]);
                        }
                    } elseif ($this->ehDescricao($linha)) {
                        $registro['descricoes'][] = $linha;
                    }
                    $anteriorEraTitulo = false;
                }
            }
        }
        $fechar();

        return $registros;
    }

    /** @return array<string, mixed> */
    private function registroVazio(int $pagina): array
    {
        return [
            'pagina' => $pagina,
            'gtins' => [],
            'codigos' => [],
            'descricoes' => [],
            'variantes' => [],
            'variantes_extra' => [],
            'titulo' => null,
            'titulo_id' => 0,
            'categoria' => null,
        ];
    }

    /** Linhas normalizadas da página, sem rodapé nem número de página. */
    private function linhasDaPagina(string $texto): array
    {
        $linhas = [];
        foreach (preg_split('/\R/u', $texto) ?: [] as $linha) {
            $linha = trim((string) preg_replace('/\s+/u', ' ', str_replace(["\t", "\u{00A0}"], ' ', $linha)));
            if ($linha !== '') {
                $linhas[] = $linha;
            }
        }

        $descartar = [];
        foreach ($linhas as $i => $linha) {
            if (! preg_match(self::PADRAO_RODAPE, $linha)) {
                continue;
            }
            $descartar[$i] = true;
            // Número da página solto ao lado do rodapé (quando não veio colado a ele).
            if (! preg_match('/^\d/', $linha)) {
                foreach ([$i - 1, $i + 1] as $vizinha) {
                    if (isset($linhas[$vizinha]) && preg_match('/^\d{1,3}$/', $linhas[$vizinha])) {
                        $descartar[$vizinha] = true;
                    }
                }
            }
        }

        return array_values(array_diff_key($linhas, $descartar));
    }

    /** Título: linha de maiúsculas com pelo menos quatro letras (rótulos usam Maiúscula Inicial). */
    private function ehTitulo(string $linha): bool
    {
        if (preg_match('/^(cat[áa]logo|s\. ?a\.)/iu', $linha)) {
            return false;
        }

        $maiusculas = preg_match_all('/\p{Lu}/u', $linha);
        $minusculas = preg_match_all('/\p{Ll}/u', $linha);

        return $maiusculas >= 4 && $maiusculas / ($maiusculas + $minusculas) >= 0.65;
    }

    /** Descrição de embalagem: começa com número ("1 caixinha c/ 200 palitos", "10 x 1000"). */
    private function ehDescricao(string $linha): bool
    {
        return (bool) preg_match('/^\d/u', $linha);
    }

    /**
     * Categoria: um título logo antes do título do produto que comece igual
     * (ex.: "ESPETINHOS" antes de "ESPETINHO PARA CHURRASCO"). Vale para os próximos
     * produtos com a mesma raiz.
     *
     * @param  list<string>  $cabecalhos
     * @param  array<string, string>  $categorias
     */
    private function categoriaDe(?string $titulo, array $cabecalhos, array &$categorias): ?string
    {
        if ($titulo === null) {
            return null;
        }

        $raiz = $this->raiz($titulo);
        foreach (array_reverse($cabecalhos) as $cabecalho) {
            if ($this->raiz($cabecalho) === $raiz && $this->raiz($cabecalho) !== '' && mb_strtolower($cabecalho) !== mb_strtolower($titulo)) {
                // A primeira ocorrência vale: o texto do PDF às vezes repete títulos depois dos dados.
                $categorias[$raiz] ??= $this->frase($cabecalho);
                break;
            }
        }

        return $categorias[$raiz] ?? null;
    }

    /** "ESPETINHOS DE MADEIRA" → "Espetinhos de madeira". */
    private function frase(string $texto): string
    {
        $minusculas = mb_strtolower($texto);

        return mb_strtoupper(mb_substr($minusculas, 0, 1)).mb_substr($minusculas, 1);
    }

    private function raiz(string $texto): string
    {
        return substr((string) preg_replace('/[^a-z0-9]/', '', Str::ascii(mb_strtolower($texto))), 0, 5);
    }

    /**
     * @param  list<array<string, mixed>>  $registros
     * @return list<array{linha: int, origem: string, dados: array<string, mixed>, avisos: list<string>}>
     */
    private function montarItens(array $registros): array
    {
        $porTitulo = [];
        foreach ($registros as $registro) {
            $porTitulo[$registro['titulo_id']] = ($porTitulo[$registro['titulo_id']] ?? 0) + 1;
        }

        $itens = [];
        $contadorTitulo = [];
        foreach ($registros as $registro) {
            $grupos = $this->grupos($registro);
            $usadas = [];
            $ordemNoTitulo = $contadorTitulo[$registro['titulo_id']] = ($contadorTitulo[$registro['titulo_id']] ?? 0) + 1;
            foreach ($grupos as $posicao => $grupo) {
                $avisos = [];
                $totalGrupos = count($grupos);
                $codigos = $registro['codigos'];

                $codigo = $totalGrupos > 1 ? ($codigos[$posicao] ?? null) : ($codigos[0] ?? null);
                $extras = $totalGrupos > 1 ? [] : array_slice($codigos, 1);

                if ($totalGrupos > 1 && count($codigos) !== $totalGrupos) {
                    $avisos[] = 'O número de variações não bate com o número de códigos do bloco; confira o código.';
                }

                $embalagens = $this->embalagens($grupo['gtins'], $registro['descricoes'], $avisos);

                if ($extras !== []) {
                    $naoUnidade = array_keys(array_filter($embalagens, fn ($e) => $e['tipo'] !== 'unidade'));
                    foreach ($extras as $j => $extra) {
                        if (isset($naoUnidade[$j])) {
                            $embalagens[$naoUnidade[$j]]['codigo'] = $extra;
                        }
                    }
                    $avisos[] = 'O bloco tem mais de um código ('.implode(', ', $codigos).'). O primeiro ficou no SKU e os demais nas embalagens; confira.';
                }

                $descricao = (string) $registro['titulo'];
                if ($grupo['marcador'] !== '' && isset($registro['variantes'][$grupo['marcador']])) {
                    $descricao .= ' - '.$registro['variantes'][$grupo['marcador']];
                    // Variações com o mesmo rótulo se distinguem pelo texto da embalagem.
                    if (in_array($descricao, $usadas, true) && isset($registro['variantes_extra'][$grupo['marcador']])) {
                        $descricao .= ' - '.$registro['variantes_extra'][$grupo['marcador']];
                    }
                    $usadas[] = $descricao;
                } elseif (($porTitulo[$registro['titulo_id']] ?? 1) > 1) {
                    $complemento = collect($registro['descricoes'])->first(fn ($d) => preg_match('/\p{L}{3}/u', $d)) ?? 'variação '.$ordemNoTitulo;
                    $descricao .= ' - '.mb_substr((string) $complemento, 0, 60);
                    $avisos[] = 'Há mais de um produto sob o título «'.$registro['titulo'].'»; confira o nome desta variação.';
                }

                if ($registro['titulo'] === null) {
                    $avisos[] = 'Produto sem título antes dos códigos.';
                }

                $itens[] = [
                    'linha' => count($itens) + 1,
                    'origem' => 'Página '.$registro['pagina'],
                    'dados' => [
                        'codigo' => $codigo ?? '',
                        'descricao' => mb_substr($descricao, 0, 200),
                        'categoria' => $registro['categoria'],
                        'embalagens' => $embalagens,
                    ],
                    'avisos' => array_values(array_unique($avisos)),
                ];
            }
        }

        return $itens;
    }

    /**
     * Separa os GTINs do bloco por marcador (•, -, *) quando há variações no mesmo bloco.
     *
     * @return list<array{marcador: string, gtins: list<string>}>
     */
    private function grupos(array $registro): array
    {
        $marcadores = array_values(array_unique(array_filter(array_column($registro['gtins'], 'marcador'))));

        if (count($marcadores) < 2) {
            return [['marcador' => $marcadores[0] ?? '', 'gtins' => $this->unicos(array_column($registro['gtins'], 'digitos'))]];
        }

        $grupos = [];
        foreach ($marcadores as $marcador) {
            $grupos[] = [
                'marcador' => $marcador,
                'gtins' => $this->unicos(array_column(
                    array_filter($registro['gtins'], fn ($g) => $g['marcador'] === $marcador || ($g['marcador'] === '' && $marcador === $marcadores[0])),
                    'digitos'
                )),
            ];
        }

        return $grupos;
    }

    /** @param  list<string>  $lista */
    private function unicos(array $lista): array
    {
        return array_values(array_unique($lista));
    }

    /**
     * Tipo de cada GTIN: o primeiro de 13 dígitos é a unidade; GTIN-14 sozinho é a caixa master;
     * com mais de um GTIN-14, os de menor indicador são reembalagem e o maior é a caixa master.
     *
     * @param  list<string>  $gtins
     * @param  list<string>  $descricoes
     * @param  list<string>  $avisos
     * @return list<array{tipo: string, quantidade: int, quantidade_conhecida: bool, gtin: string, codigo: ?string}>
     */
    private function embalagens(array $gtins, array $descricoes, array &$avisos): array
    {
        $curtos = array_values(array_filter($gtins, fn ($g) => strlen($g) < 14));
        $longos = array_values(array_filter($gtins, fn ($g) => strlen($g) === 14));
        usort($longos, fn ($a, $b) => (int) $a[0] <=> (int) $b[0]);

        $tipos = [];
        foreach ($curtos as $i => $gtin) {
            $tipos[] = [['unidade', 'reembalagem', 'caixa_master'][min($i, 2)], $gtin];
        }
        foreach ($longos as $i => $gtin) {
            $tipos[] = [count($longos) === 1 || $i === count($longos) - 1 ? 'caixa_master' : 'reembalagem', $gtin];
        }

        $ordem = ['unidade' => 0, 'reembalagem' => 1, 'caixa_master' => 2];
        usort($tipos, fn ($a, $b) => $ordem[$a[0]] <=> $ordem[$b[0]]);

        // As descrições só valem quando há uma por embalagem, na mesma ordem.
        $alinhadas = count($descricoes) === count($tipos);
        $desconhecida = false;

        $embalagens = [];
        foreach ($tipos as $i => [$tipo, $gtin]) {
            $quantidade = 1;
            $conhecida = true;
            if ($tipo !== 'unidade') {
                $numero = $alinhadas ? $this->primeiroNumero($descricoes[$i]) : null;
                $conhecida = $numero !== null;
                $quantidade = $numero ?? 1;
                $desconhecida = $desconhecida || ! $conhecida;
            }
            $embalagens[] = [
                'tipo' => $tipo,
                'quantidade' => $quantidade,
                'quantidade_conhecida' => $conhecida,
                'gtin' => $gtin,
                'codigo' => null,
            ];
        }

        if ($desconhecida) {
            $avisos[] = 'Quantidade das embalagens não identificada no texto (ficou 1); confira.';
        }

        return $embalagens;
    }

    private function primeiroNumero(string $texto): ?int
    {
        if (! preg_match('/\d{1,3}(?:\.\d{3})+|\d+/', $texto, $m)) {
            return null;
        }
        $numero = (int) str_replace('.', '', $m[0]);

        return $numero >= 1 ? $numero : null;
    }
}
