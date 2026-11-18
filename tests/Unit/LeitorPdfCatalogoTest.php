<?php

namespace Tests\Unit;

use App\Services\Catalogo\LeitorPdfCatalogo;
use PHPUnit\Framework\TestCase;

/**
 * O texto de `tests/Fixtures/catalogo_gaboardi_texto.txt` é o que o leitor de PDF extrai do
 * catálogo da Gaboardi (12 páginas). Cobre os layouts que o catálogo mistura.
 */
class LeitorPdfCatalogoTest extends TestCase
{
    /** @var array<string, array> código => item */
    private array $itens;

    private array $lista;

    protected function setUp(): void
    {
        parent::setUp();

        $texto = file_get_contents(__DIR__.'/../Fixtures/catalogo_gaboardi_texto.txt');
        $paginas = [];
        foreach (preg_split('/^##### PAG (\d+)\R/m', $texto, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $i => $parte) {
            if ($i % 2 === 0) {
                $numero = (int) $parte;
            } else {
                $paginas[$numero] = $parte;
            }
        }

        $this->lista = (new LeitorPdfCatalogo)->interpretar($paginas);
        $this->itens = collect($this->lista)->keyBy(fn ($i) => $i['dados']['codigo'])->all();
    }

    private function embalagens(string $codigo): array
    {
        return collect($this->itens[$codigo]['dados']['embalagens'])
            ->map(fn ($e) => [$e['tipo'], $e['gtin']])
            ->all();
    }

    public function test_reconhece_todos_os_produtos_com_codigo_unico(): void
    {
        $this->assertCount(69, $this->lista);
        $this->assertCount(69, $this->itens, 'códigos repetidos ou vazios');
        $this->assertArrayNotHasKey('', $this->itens);
        $this->assertSame(range(1, 69), array_column($this->lista, 'linha'));
    }

    public function test_produto_com_tres_gtins_separa_unidade_reembalagem_e_caixa_master(): void
    {
        $item = $this->itens['10FL01005'];

        $this->assertSame('FÓSFOROS DE SEGURANÇA LONGOS', $item['dados']['descricao']);
        $this->assertSame('Página 2', $item['origem']);
        $this->assertSame([
            ['unidade', '7896279200011'],
            ['reembalagem', '17896279200018'],
            ['caixa_master', '27896279200015'],
        ], $this->embalagens('10FL01005'));

        // Quantidades vêm das linhas de descrição quando há uma por embalagem.
        $this->assertSame([1, 6, 120], array_column($item['dados']['embalagens'], 'quantidade'));
        $this->assertSame([true, true, true], array_column($item['dados']['embalagens'], 'quantidade_conhecida'));
    }

    public function test_gtin_na_ordem_do_catalogo_nao_troca_o_tipo(): void
    {
        // No catálogo a caixa master (2…) vem antes da reembalagem (1…).
        $this->assertSame([
            ['unidade', '7896279212717'],
            ['reembalagem', '17896279212714'],
            ['caixa_master', '27896279212711'],
        ], $this->embalagens('10FS01702'));
        $this->assertSame('FÓSFOROS JESUS | EDIÇÃO LIMITADA 30 PALITOS', $this->itens['10FS01702']['dados']['descricao']);
    }

    public function test_quantidade_nao_identificada_fica_1_com_aviso(): void
    {
        $item = $this->itens['10FS01702'];

        $this->assertSame([1, 1, 1], array_column($item['dados']['embalagens'], 'quantidade'));
        $this->assertSame([true, false, false], array_column($item['dados']['embalagens'], 'quantidade_conhecida'));
        $this->assertContains('Quantidade das embalagens não identificada no texto (ficou 1); confira.', $item['avisos']);
    }

    public function test_gtin14_sozinho_e_a_caixa_master(): void
    {
        $this->assertSame([
            ['unidade', '7896384500044'],
            ['caixa_master', '17896384500041'],
        ], $this->embalagens('20LF01303'));
        $this->assertSame(10, $this->itens['20LF01303']['dados']['embalagens'][1]['quantidade']);
    }

    public function test_variacoes_marcadas_no_mesmo_bloco_viram_produtos_separados(): void
    {
        $this->assertSame('ESPETINHO PARA CHURRASCO MADEIRA - Ø 3,0 x 180 mm', $this->itens['20LF01104']['dados']['descricao']);
        $this->assertSame('ESPETINHO PARA CHURRASCO MADEIRA - Ø 3,5 x 250 mm', $this->itens['20LF01100']['dados']['descricao']);
        $this->assertNotSame($this->itens['20LF01100']['dados']['descricao'], $this->itens['20LF01111']['dados']['descricao']);

        $this->assertSame([
            ['unidade', '7896384500075'],
            ['reembalagem', '7896384500072'],
            ['caixa_master', '7896384500079'],
        ], $this->embalagens('20LF01104'));
        $this->assertSame('7896279200202', $this->itens['20LF01111']['dados']['embalagens'][0]['gtin']);
    }

    public function test_categoria_vem_do_titulo_anterior_de_mesma_raiz(): void
    {
        $this->assertSame('Espetinhos', $this->itens['20LF01104']['dados']['categoria']);
        $this->assertSame('Espetinhos', $this->itens['20LF01101']['dados']['categoria'], 'vale para os produtos seguintes');
        $this->assertSame('Alumgrill', $this->itens['20LA02003']['dados']['categoria']);
        $this->assertSame('Fósforos de segurança', $this->itens['10FS01702']['dados']['categoria']);
        $this->assertNull($this->itens['20LF01303']['dados']['categoria']);
    }

    public function test_produtos_sob_o_mesmo_titulo_sao_diferenciados_e_avisados(): void
    {
        $dentais = collect($this->itens)->filter(fn ($i) => str_starts_with($i['dados']['descricao'], 'PALITOS DENTAIS'));

        $this->assertCount(2, $dentais);
        $this->assertSame(2, $dentais->pluck('dados.descricao')->unique()->count());
        $dentais->each(fn ($i) => $this->assertTrue(
            collect($i['avisos'])->contains(fn ($a) => str_contains($a, 'mais de um produto sob o título'))
        ));
    }

    public function test_bloco_com_dois_codigos_guarda_o_segundo_na_embalagem_e_avisa(): void
    {
        $item = $this->itens['20LA02001'];

        $this->assertSame('ALUMGRILL 30cm x 4m', $item['dados']['descricao']);
        $this->assertArrayNotHasKey('20LA02006', $this->itens);
        $this->assertContains('20LA02006', array_column($item['dados']['embalagens'], 'codigo'));
        $this->assertTrue(collect($item['avisos'])->contains(fn ($a) => str_contains($a, 'mais de um código')));
    }

    public function test_codigo_com_tres_letras_e_gtin_repetido_no_bloco(): void
    {
        $item = $this->itens['20LCA1001'];

        $this->assertSame('CACHAÇA GABOARDI ALAMBIQUE 18', $item['dados']['descricao']);
        $this->assertCount(1, $item['dados']['embalagens']);
        $this->assertSame('7896279200547', $item['dados']['embalagens'][0]['gtin']);
    }

    public function test_rodape_e_texto_da_ultima_pagina_nao_viram_produto(): void
    {
        foreach ($this->lista as $item) {
            $this->assertStringNotContainsStringIgnoringCase('catálogo de produtos', $item['dados']['descricao']);
            $this->assertStringNotContainsString('S. A.', $item['dados']['descricao']);
        }
    }

    public function test_gtin_com_erro_de_digitacao_continua_no_produto(): void
    {
        // O catálogo traz '178967920384' (12 dígitos) e '1786279200247' (13); a análise os sinaliza.
        $this->assertContains('178967920384', array_column($this->itens['10LG01002']['dados']['embalagens'], 'gtin'));
        $this->assertContains('1786279200247', array_column($this->itens['20LF01115']['dados']['embalagens'], 'gtin'));
    }

    public function test_texto_sem_produtos_devolve_lista_vazia(): void
    {
        $this->assertSame([], (new LeitorPdfCatalogo)->interpretar([1 => "Apresentação\nTexto qualquer\nSem códigos"]));
    }
}
