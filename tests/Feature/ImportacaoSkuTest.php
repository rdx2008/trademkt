<?php

namespace Tests\Feature;

use App\Enums\Perfil;
use App\Models\Industria;
use App\Models\Sku;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class ImportacaoSkuTest extends TestCase
{
    private const URL = 'http://gaboardi.test';

    private Tenant $tenant;

    private Industria $marca;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->criarCliente('gaboardi');
        $this->marca = $this->tenant->run(fn () => Industria::first());
    }

    private function admin(): User
    {
        return $this->tenant->run(fn () => User::where('perfil', Perfil::AdminInstalacao->value)->first());
    }

    /** CSV como o Excel brasileiro salva: ponto e vírgula e Windows-1252. */
    private function csv(array $linhas): UploadedFile
    {
        $texto = implode("\r\n", array_map(fn ($l) => implode(';', $l), $linhas))."\r\n";

        return UploadedFile::fake()->createWithContent('produtos.csv', mb_convert_encoding($texto, 'Windows-1252', 'UTF-8'));
    }

    private function xlsx(array $linhas): UploadedFile
    {
        $caminho = tempnam(sys_get_temp_dir(), 'skus').'.xlsx';
        $escritor = new Writer;
        $escritor->openToFile($caminho);
        foreach ($linhas as $linha) {
            $escritor->addRow(Row::fromValues($linha));
        }
        $escritor->close();

        return new UploadedFile($caminho, 'produtos.xlsx', null, null, true);
    }

    /** PDF mínimo com uma linha de texto por item (Helvetica, só ASCII). */
    private function pdf(array $linhas): UploadedFile
    {
        $conteudo = "BT\n/F1 12 Tf\n50 780 Td\n14 TL\n";
        foreach ($linhas as $linha) {
            $conteudo .= '('.str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $linha).") Tj\nT*\n";
        }
        $conteudo .= 'ET';

        $objetos = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            4 => '<< /Length '.strlen($conteudo)." >>\nstream\n{$conteudo}\nendstream",
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];

        $pdf = "%PDF-1.4\n";
        $posicoes = [];
        foreach ($objetos as $numero => $objeto) {
            $posicoes[$numero] = strlen($pdf);
            $pdf .= "{$numero} 0 obj\n{$objeto}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        foreach ($posicoes as $posicao) {
            $pdf .= sprintf("%010d 00000 n \n", $posicao);
        }
        $pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";

        return UploadedFile::fake()->createWithContent('catalogo.pdf', $pdf);
    }

    /** EAN-13 válido a partir dos 12 primeiros dígitos. */
    private function ean(string $base): string
    {
        $soma = 0;
        foreach (str_split($base) as $i => $digito) {
            $soma += (int) $digito * ($i % 2 === 0 ? 1 : 3);
        }

        return $base.((10 - $soma % 10) % 10);
    }

    private function revisaoId(string $local): string
    {
        return basename(parse_url($local, PHP_URL_PATH));
    }

    private function enviar(UploadedFile $arquivo, ?int $industriaId = null): string
    {
        $resposta = $this->post(self::URL.'/skus/importar', ['industria_id' => $industriaId ?? $this->marca->id, 'arquivo' => $arquivo])
            ->assertSessionHasNoErrors();

        return $this->revisaoId($resposta->headers->get('Location'));
    }

    public function test_importa_planilha_com_revisao_antes_de_gravar(): void
    {
        $unidade = $this->ean('789100031550');
        $gtinErrado = substr($this->ean('789100031551'), 0, 12).'0';
        if ($gtinErrado === $this->ean('789100031551')) {
            $gtinErrado = substr($gtinErrado, 0, 12).'1';
        }

        // Já cadastrado: a planilha atualiza pelo código, sem duplicar.
        $existente = $this->tenant->run(function () use ($unidade) {
            $sku = Sku::create(['industria_id' => $this->marca->id, 'codigo' => 'ALG-25', 'descricao' => 'Nome antigo', 'categoria' => 'Descartáveis']);
            $sku->embalagens()->create(['tipo' => 'unidade', 'quantidade' => 1, 'gtin' => $unidade]);
            $sku->embalagens()->create(['tipo' => 'caixa_master', 'quantidade' => 25, 'gtin' => '17891000315504', 'codigo' => 'CX25']);

            return $sku;
        });

        $arquivo = $this->csv([
            ['Código', 'Descrição', 'Categoria', 'GTIN unidade', 'GTIN reembalagem', 'Qtd reembalagem', 'GTIN caixa master', 'Qtd caixa master'],
            ['ALG-25', 'Alumgrill 30 cm', '', $unidade, '', '', '17891000315504', '30'],
            ['DET-01', 'Detergente 500 ml', 'Limpeza', $this->ean('789100099901'), '', '', '', ''],
            ['', 'Sem código', '', '', '', '', '', ''],
            ['SEM-DESC', '', '', '', '', '', '', ''],
            ['GTIN-RUIM', 'GTIN com dígito errado', '', $gtinErrado, '', '', '', ''],
            ['GTIN-CURTO', 'GTIN curto', '', '12345', '', '', '', ''],
            ['DET-01', 'Detergente 500 ml', 'Limpeza', '', '', '', '', ''],
            ['', '', '', '', '', '', '', ''],
        ]);

        $this->actingAs($this->admin());

        $id = $this->enviar($arquivo);

        // Nada gravado antes da confirmação.
        $this->tenant->run(fn () => $this->assertSame(1, Sku::count()));

        $this->get(self::URL."/skus/importar/{$id}")
            ->assertOk()
            ->assertSee('2 novo(s), 1 a atualizar, 3 com erro')
            ->assertSee('Detergente 500 ml')
            ->assertSee('Descrição cadastrada hoje: «Nome antigo». Será substituída.', false)
            ->assertSee('Linhas do mesmo código foram unidas (Linha 8).', false)
            ->assertSee('com dígito verificador errado')
            ->assertSee('O campo código é obrigatório.', false)
            ->assertSee('O campo descrição é obrigatório.', false)
            ->assertSee('O GTIN deve ter 8, 12, 13 ou 14 dígitos.', false);

        // Linhas 2, 3 e 6: as outras com erro são ignoradas mesmo se enviadas.
        $this->post(self::URL."/skus/importar/{$id}", ['itens' => [2, 3, 4, 5, 6, 7]])
            ->assertRedirect(self::URL.'/skus')
            ->assertSessionHas('status', fn ($s) => str_contains($s, '2 SKU(s) criado(s), 1 atualizado(s).')
                && str_contains($s, '3 item(ns) com erro ignorado(s).')
                && str_contains($s, '1 SKU(s) com GTIN a revisar'));

        $this->tenant->run(function () use ($existente, $gtinErrado) {
            $this->assertSame(3, Sku::count());

            $atualizado = $existente->fresh()->load('embalagens');
            $this->assertSame('Alumgrill 30 cm', $atualizado->descricao);
            $this->assertSame('Descartáveis', $atualizado->categoria, 'Categoria vazia mantém o valor.');
            $this->assertCount(2, $atualizado->embalagens, 'Reimportar não duplica embalagens.');
            $caixa = $atualizado->embalagens->firstWhere('tipo.value', 'caixa_master');
            $this->assertSame(30, $caixa->quantidade);
            $this->assertSame('CX25', $caixa->codigo, 'Código da embalagem vazio mantém o valor.');

            $novo = Sku::where('codigo', 'DET-01')->with('embalagens')->sole();
            $this->assertSame('Limpeza', $novo->categoria);
            $this->assertTrue($novo->ativo);
            $this->assertCount(1, $novo->embalagens);

            $ruim = Sku::where('codigo', 'GTIN-RUIM')->with('embalagens')->sole();
            $this->assertSame($gtinErrado, $ruim->embalagens->sole()->gtin);
            $this->assertFalse($ruim->embalagens->sole()->gtin_valido);

            $this->assertSame(0, Sku::whereIn('codigo', ['SEM-DESC', 'GTIN-CURTO'])->count());
        });

        // Revisão consumida: o link não vale mais.
        $this->get(self::URL."/skus/importar/{$id}")->assertNotFound();
    }

    public function test_importa_xlsx_no_formato_longo_com_gtin_numerico(): void
    {
        $this->actingAs($this->admin());

        $id = $this->enviar($this->xlsx([
            ['Código', 'Produto', 'Tipo', 'Quantidade', 'EAN'],
            ['P-1', 'Papel alumínio', 'Unidade', 1, 7891000315507],
            ['P-1', 'Papel alumínio', 'Caixa master', '25 UN', '17891000315504'],
            ['P-1', 'Papel alumínio', 'Reembalagem', 5, 7.891000315507E+12],
            ['P-2', 'Esponja', 'Palete', 1, ''],
        ]));

        $this->get(self::URL."/skus/importar/{$id}")
            ->assertOk()
            ->assertSee('1 novo(s), 0 a atualizar, 1 com erro')
            ->assertSee('Tipo de embalagem desconhecido');

        $this->post(self::URL."/skus/importar/{$id}", ['itens' => [2, 5]])->assertSessionHasNoErrors();

        $this->tenant->run(function () {
            $sku = Sku::where('codigo', 'P-1')->with('embalagens')->sole();
            $this->assertCount(3, $sku->embalagens);
            $this->assertSame('7891000315507', $sku->embalagens->firstWhere('tipo.value', 'unidade')->gtin);
            $this->assertSame(25, $sku->embalagens->firstWhere('tipo.value', 'caixa_master')->quantidade);
            $this->assertSame(0, Sku::where('codigo', 'P-2')->count(), 'Tipo inválido bloqueia a gravação.');
        });
    }

    public function test_planilha_sem_colunas_obrigatorias_ou_vazia_e_recusada(): void
    {
        $this->actingAs($this->admin());

        $this->post(self::URL.'/skus/importar', [
            'industria_id' => $this->marca->id,
            'arquivo' => $this->csv([['Nome', 'Preço'], ['Algo', '10']]),
        ])->assertSessionHasErrors('arquivo');

        $this->post(self::URL.'/skus/importar', [
            'industria_id' => $this->marca->id,
            'arquivo' => $this->csv([['Código', 'Descrição']]),
        ])->assertSessionHasErrors('arquivo');
    }

    public function test_importa_pdf_com_texto_selecionavel(): void
    {
        $this->actingAs($this->admin());
        $unidade = $this->ean('789100031550');
        $caixa = '1'.$this->ean('789100031551');
        // GTIN-14 válido a partir dos 13 primeiros dígitos.
        $base = '1789100031550';
        $soma = 0;
        foreach (str_split($base) as $i => $d) {
            $soma += (int) $d * ($i % 2 === 0 ? 3 : 1);
        }
        $caixa = $base.((10 - $soma % 10) % 10);

        $id = $this->enviar($this->pdf([
            'CERA LIQUIDA BRILHO',
            'Embalagem Individual',
            'Caixa master',
            'Codigo do Produto',
            '1',
            '12 UN',
            $unidade,
            $caixa,
            '10AB00001',
        ]));

        $this->tenant->run(fn () => $this->assertSame(0, Sku::count()));

        $this->get(self::URL."/skus/importar/{$id}")
            ->assertOk()
            ->assertSee('PDF')
            ->assertSee('1 novo(s), 0 a atualizar, 0 com erro')
            ->assertSee('CERA LIQUIDA BRILHO')
            ->assertSee('10AB00001')
            ->assertSee('Caixa master')
            ->assertSee('Página 1');

        $this->post(self::URL."/skus/importar/{$id}", ['itens' => [1]])->assertSessionHasNoErrors();

        $this->tenant->run(function () use ($unidade, $caixa) {
            $sku = Sku::where('codigo', '10AB00001')->with('embalagens')->sole();
            $this->assertSame('CERA LIQUIDA BRILHO', $sku->descricao);
            $this->assertSame($this->marca->id, $sku->industria_id);
            $this->assertSame([$unidade, $caixa], $sku->embalagensOrdenadas()->pluck('gtin')->all());
            $this->assertSame([1, 12], $sku->embalagensOrdenadas()->pluck('quantidade')->all());
        });
    }

    public function test_reimportar_pdf_nao_zera_quantidade_que_o_texto_nao_informa(): void
    {
        $this->actingAs($this->admin());
        $unidade = $this->ean('789100031550');
        $base = '1789100031550';
        $soma = 0;
        foreach (str_split($base) as $i => $d) {
            $soma += (int) $d * ($i % 2 === 0 ? 3 : 1);
        }
        $caixa = $base.((10 - $soma % 10) % 10);

        $this->tenant->run(function () use ($caixa) {
            $sku = Sku::create(['industria_id' => $this->marca->id, 'codigo' => '10AB00002', 'descricao' => 'Antigo']);
            $sku->embalagens()->create(['tipo' => 'caixa_master', 'quantidade' => 12, 'gtin' => $caixa]);
        });

        // Sem linhas de descrição: o leitor só supõe quantidade 1.
        $id = $this->enviar($this->pdf(['PRODUTO TESTE', $unidade, $caixa, '10AB00002']));

        $this->get(self::URL."/skus/importar/{$id}")
            ->assertSee('0 novo(s), 1 a atualizar')
            ->assertSee('Quantidade das embalagens não identificada');

        $this->post(self::URL."/skus/importar/{$id}", ['itens' => [1]])->assertSessionHasNoErrors();

        $this->tenant->run(function () use ($caixa, $unidade) {
            $sku = Sku::where('codigo', '10AB00002')->with('embalagens')->sole();
            $this->assertSame('PRODUTO TESTE', $sku->descricao);
            $this->assertCount(2, $sku->embalagens);
            $this->assertSame(12, $sku->embalagens->firstWhere('gtin', $caixa)->quantidade);
            $this->assertSame(1, $sku->embalagens->firstWhere('gtin', $unidade)->quantidade);
        });
    }

    public function test_pdf_invalido_ou_sem_texto_e_recusado_com_mensagem(): void
    {
        $this->actingAs($this->admin());

        $this->post(self::URL.'/skus/importar', [
            'industria_id' => $this->marca->id,
            'arquivo' => UploadedFile::fake()->createWithContent('falso.pdf', 'isto não é um pdf'),
        ])->assertSessionHasErrors(['arquivo' => 'O arquivo não é um PDF válido.']);

        $this->post(self::URL.'/skus/importar', [
            'industria_id' => $this->marca->id,
            'arquivo' => $this->pdf([]),
        ])->assertSessionHasErrors('arquivo');

        $this->post(self::URL.'/skus/importar', [
            'industria_id' => $this->marca->id,
            'arquivo' => $this->pdf(['Apresentação institucional da empresa sem nenhum produto listado']),
        ])->assertSessionHasErrors('arquivo');
    }

    public function test_gtin_ja_usado_por_outro_sku_gera_aviso_mas_nao_bloqueia(): void
    {
        $this->actingAs($this->admin());
        $gtin = $this->ean('789100031550');
        $this->tenant->run(function () use ($gtin) {
            Sku::create(['industria_id' => $this->marca->id, 'codigo' => 'OUTRO', 'descricao' => 'Outro'])
                ->embalagens()->create(['tipo' => 'unidade', 'quantidade' => 1, 'gtin' => $gtin]);
        });

        $id = $this->enviar($this->csv([
            ['Código', 'Descrição', 'GTIN unidade'],
            ['NOVO-1', 'Novo um', $gtin],
            ['NOVO-2', 'Novo dois', $gtin],
        ]));

        $this->get(self::URL."/skus/importar/{$id}")
            ->assertSee('já cadastrado no SKU OUTRO')
            ->assertSee('repetido no arquivo (também em NOVO-1)')
            ->assertSee('2 novo(s), 0 a atualizar, 0 com erro');
    }

    public function test_gerente_de_trade_importa_so_na_propria_marca(): void
    {
        $outra = $this->tenant->run(fn () => Industria::create(['nome' => 'Outra marca']));
        $gerente = $this->criarUsuario($this->tenant, Perfil::GerenteTrade, ['industria_id' => $this->marca->id]);
        $this->actingAs($gerente);

        $this->get(self::URL.'/skus/importar')->assertOk()->assertDontSee('Outra marca');

        // Mesmo mandando outra indústria, a importação vai para a marca do gerente.
        $id = $this->enviar($this->csv([['Código', 'Descrição'], ['G-1', 'Produto do gerente']]), $outra->id);
        $this->post(self::URL."/skus/importar/{$id}", ['itens' => [2]])->assertSessionHasNoErrors();

        $this->tenant->run(function () use ($outra) {
            $this->assertSame($this->marca->id, Sku::where('codigo', 'G-1')->sole()->industria_id);
            $this->assertSame(0, Sku::where('industria_id', $outra->id)->count());
        });
    }

    public function test_admin_precisa_escolher_industria_valida(): void
    {
        $this->actingAs($this->admin());
        $outra = $this->tenant->run(fn () => Industria::create(['nome' => 'Outra marca']));

        $this->get(self::URL.'/skus/importar')->assertOk()->assertSee('Outra marca');

        $this->post(self::URL.'/skus/importar', ['arquivo' => $this->csv([['Código', 'Descrição'], ['A', 'B']])])
            ->assertSessionHasErrors('industria_id');
        $this->post(self::URL.'/skus/importar', ['industria_id' => 9999, 'arquivo' => $this->csv([['Código', 'Descrição'], ['A', 'B']])])
            ->assertSessionHasErrors('industria_id');

        $id = $this->enviar($this->csv([['Código', 'Descrição'], ['A', 'B']]), $outra->id);
        $this->post(self::URL."/skus/importar/{$id}", ['itens' => [2]]);
        $this->tenant->run(fn () => $this->assertSame($outra->id, Sku::where('codigo', 'A')->sole()->industria_id));
    }

    public function test_outros_perfis_e_outros_usuarios_nao_acessam(): void
    {
        $this->actingAs($this->admin());
        $id = $this->enviar($this->csv([['Código', 'Descrição'], ['A', 'B']]));

        $representante = $this->criarUsuario($this->tenant, Perfil::Representante, ['industria_id' => $this->marca->id]);
        $this->actingAs($representante);
        $this->get(self::URL.'/skus/importar')->assertForbidden();
        $this->post(self::URL.'/skus/importar', ['industria_id' => $this->marca->id, 'arquivo' => $this->csv([['Código', 'Descrição'], ['A', 'B']])])->assertForbidden();

        // Outro gerente de trade não abre nem grava a revisão de quem a criou.
        $gerente = $this->criarUsuario($this->tenant, Perfil::GerenteTrade, ['industria_id' => $this->marca->id]);
        $this->actingAs($gerente);
        $this->get(self::URL."/skus/importar/{$id}")->assertNotFound();
        $this->post(self::URL."/skus/importar/{$id}", ['itens' => [2]])->assertNotFound();
        $this->get(self::URL.'/skus/importar/nao-e-uuid')->assertNotFound();
        $this->tenant->run(fn () => $this->assertSame(0, Sku::count()));
    }

    public function test_descartar_nao_grava_nada_e_exige_selecao_para_gravar(): void
    {
        $this->actingAs($this->admin());
        $id = $this->enviar($this->csv([['Código', 'Descrição'], ['A', 'B']]));

        $this->post(self::URL."/skus/importar/{$id}", [])->assertSessionHasErrors('itens');

        $this->delete(self::URL."/skus/importar/{$id}")
            ->assertRedirect(self::URL.'/skus/importar')
            ->assertSessionHas('status', 'Importação descartada. Nada foi gravado.');

        $this->get(self::URL."/skus/importar/{$id}")->assertNotFound();
        $this->tenant->run(fn () => $this->assertSame(0, Sku::count()));
    }

    public function test_baixa_planilha_modelo_e_ela_pode_ser_importada(): void
    {
        $this->actingAs($this->admin());

        $resposta = $this->get(self::URL.'/skus/importar/modelo')->assertOk();
        $this->assertStringContainsString('modelo-produtos.xlsx', $resposta->headers->get('content-disposition'));

        $caminho = tempnam(sys_get_temp_dir(), 'modelo').'.xlsx';
        file_put_contents($caminho, $resposta->streamedContent());

        $id = $this->enviar(new UploadedFile($caminho, 'modelo.xlsx', null, null, true));
        $this->get(self::URL."/skus/importar/{$id}")->assertSee('2 novo(s), 0 a atualizar, 0 com erro');
    }

    public function test_botao_de_importar_aparece_no_catalogo_para_quem_cadastra(): void
    {
        $this->actingAs($this->admin());
        $this->get(self::URL.'/skus')->assertOk()->assertSee('Importar produtos');

        $representante = $this->criarUsuario($this->tenant, Perfil::Representante, ['industria_id' => $this->marca->id]);
        $this->actingAs($representante);
        $this->get(self::URL.'/skus')->assertOk()->assertDontSee('Importar produtos');
    }
}
