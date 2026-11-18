<?php

namespace Tests\Feature;

use App\Enums\Perfil;
use App\Jobs\GeocodificarPdv;
use App\Models\Agencia;
use App\Models\Pdv;
use App\Models\Rede;
use App\Models\Regiao;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class ImportacaoPdvTest extends TestCase
{
    private const URL = 'http://gaboardi.test';

    private function admin(Tenant $tenant): User
    {
        return $tenant->run(fn () => User::where('perfil', Perfil::AdminInstalacao->value)->first());
    }

    /** CSV como o Excel brasileiro salva: ponto e vírgula e Windows-1252. */
    private function csv(array $linhas): UploadedFile
    {
        $texto = implode("\r\n", array_map(fn ($l) => implode(';', $l), $linhas))."\r\n";

        return UploadedFile::fake()->createWithContent('pdvs.csv', mb_convert_encoding($texto, 'Windows-1252', 'UTF-8'));
    }

    private function revisaoId(string $local): string
    {
        return basename(parse_url($local, PHP_URL_PATH));
    }

    public function test_importa_csv_com_revisao_antes_de_gravar(): void
    {
        Queue::fake();
        $tenant = $this->criarCliente('gaboardi');

        // Já cadastrado: a planilha atualiza pelo CNPJ.
        $existente = $tenant->run(fn () => Pdv::create([
            'cnpj' => self::cnpj(1), 'nome' => 'Nome antigo', 'cidade' => 'Caxias do Sul', 'uf' => 'RS',
            'bairro' => 'Bairro antigo', 'raio_m' => 250,
        ]));

        $arquivo = $this->csv([
            ['CNPJ', 'Nome da loja', 'Rede', 'Canal', 'Região', 'Endereço', 'Nº', 'Bairro', 'Município', 'UF', 'Latitude', 'Longitude', 'Raio'],
            [self::cnpj(1), 'Nome novo', 'Rede Sul', 'Supermercado', 'Serra', 'Rua A', '10', '', 'Caxias do Sul', 'RS', '', '', ''],
            [self::cnpj(2), 'Mercado São João', 'rede sul', 'Farmácia', 'Serra', 'Rua B', '20', 'Centro', 'Farroupilha', 'rs', '-29,22', '-51,34', '180'],
            ['11.222.333/0001-82', 'CNPJ errado', '', '', '', '', '', '', 'Caxias do Sul', 'RS', '', '', ''],
            [self::cnpj(3), 'Raio grande', '', '', '', '', '', '', 'Caxias do Sul', 'RS', '', '', '400'],
            [self::cnpj(2), 'Repetido', '', '', '', '', '', '', 'Caxias do Sul', 'RS', '', '', ''],
            [self::cnpj(4), 'Canal estranho', '', 'Padaria', '', '', '', '', 'Caxias do Sul', 'RS', '', '', ''],
            [self::cnpj(5), 'Sem cidade', '', '', '', '', '', '', '', 'RS', '', '', ''],
            ['', '', '', '', '', '', '', '', '', '', '', '', ''],
        ]);

        $this->actingAs($this->admin($tenant));

        $resposta = $this->post(self::URL.'/pdvs/importar', ['arquivo' => $arquivo])->assertSessionHasNoErrors();
        $id = $this->revisaoId($resposta->headers->get('Location'));

        // Nada gravado antes da confirmação.
        $tenant->run(fn () => $this->assertSame(1, Pdv::count()));

        $this->get(self::URL."/pdvs/importar/{$id}")
            ->assertOk()
            ->assertSee('1 novo(s), 1 a atualizar, 5 com erro')
            ->assertSee('Mercado São João')
            ->assertSee('O CNPJ informado não é um CNPJ válido.')
            ->assertSee('O raio de check-in deve ficar entre 150 e 300 metros.')
            ->assertSee('CNPJ repetido na planilha (linha 3).')
            ->assertSee('Canal desconhecido: Padaria.')
            ->assertSee('O campo cidade é obrigatório.', false);

        // Tenta gravar também uma linha com erro (linha 4): é ignorada.
        $this->post(self::URL."/pdvs/importar/{$id}", ['linhas' => [2, 3, 4]])
            ->assertRedirect(self::URL.'/pdvs')
            ->assertSessionHas('status', 'Importação concluída: 1 PDV(s) criado(s), 1 atualizado(s). 1 linha(s) com erro ignorada(s).');

        $tenant->run(function () use ($existente) {
            $this->assertSame(2, Pdv::count());
            $this->assertSame(1, Rede::count(), 'Rede com nome em caixa diferente não duplica.');
            $this->assertSame(1, Regiao::count());

            $atualizado = $existente->fresh();
            $this->assertSame('Nome novo', $atualizado->nome);
            $this->assertSame('Bairro antigo', $atualizado->bairro, 'Célula vazia mantém o valor.');
            $this->assertSame(250, $atualizado->raio_m, 'Raio vazio mantém o valor.');
            $this->assertSame('Rede Sul', $atualizado->rede->nome);
            $this->assertSame('supermercado', $atualizado->canal->value);

            $novo = Pdv::where('cnpj', self::cnpj(2))->sole();
            $this->assertSame('Mercado São João', $novo->nome);
            $this->assertSame('farmacia', $novo->canal->value);
            $this->assertSame('RS', $novo->uf);
            $this->assertSame(180, $novo->raio_m);
            $this->assertEqualsWithDelta(-29.22, $novo->lat, 1e-6);
            $this->assertSame('planilha', $novo->coordenadas_origem);
            $this->assertSame('Serra', $novo->regiao->nome);
        });

        // Só o PDV sem coordenadas vai para o geocoding.
        Queue::assertPushed(GeocodificarPdv::class, 1);
        Queue::assertPushed(GeocodificarPdv::class, fn ($job) => $job->pdvId === $existente->id);

        // A revisão é descartada depois de gravar.
        $this->get(self::URL."/pdvs/importar/{$id}")->assertNotFound();
    }

    public function test_importa_xlsx_com_cnpj_numerico(): void
    {
        Queue::fake();
        $tenant = $this->criarCliente('gaboardi');

        $caminho = tempnam(sys_get_temp_dir(), 'pdvs').'.xlsx';
        $escritor = new Writer;
        $escritor->openToFile($caminho);
        $escritor->addRow(Row::fromValues(['cnpj', 'loja', 'cidade', 'estado', 'lat', 'lng', 'raio_m']));
        // CNPJ que começa com zero, digitado como número: o Excel perde o zero.
        $escritor->addRow(Row::fromValues([(int) self::cnpj(1234567), 'Loja Zero', 'Bento Gonçalves', 'RS', -29.17, -51.52, 200]));
        $escritor->close();

        $this->actingAs($this->admin($tenant));

        $resposta = $this->post(self::URL.'/pdvs/importar', [
            'arquivo' => new UploadedFile($caminho, 'lojas.xlsx', null, null, true),
        ])->assertSessionHasNoErrors();
        $id = $this->revisaoId($resposta->headers->get('Location'));

        $this->get(self::URL."/pdvs/importar/{$id}")->assertOk()->assertSee('1 novo(s), 0 a atualizar, 0 com erro');
        $this->post(self::URL."/pdvs/importar/{$id}", ['linhas' => [2]])->assertRedirect(self::URL.'/pdvs');

        $tenant->run(function () {
            $pdv = Pdv::sole();
            $this->assertSame(self::cnpj(1234567), $pdv->cnpj);
            $this->assertStringStartsWith('0', $pdv->cnpj);
            $this->assertSame('Bento Gonçalves', $pdv->cidade);
            $this->assertEqualsWithDelta(-51.52, $pdv->lng, 1e-6);
        });

        Queue::assertNothingPushed();
        @unlink($caminho);
    }

    public function test_planilha_sem_colunas_obrigatorias_e_recusada(): void
    {
        $tenant = $this->criarCliente('gaboardi');
        $this->actingAs($this->admin($tenant));

        $this->post(self::URL.'/pdvs/importar', ['arquivo' => $this->csv([['Nome', 'Cidade'], ['Loja', 'Caxias']])])
            ->assertSessionHasErrors(['arquivo' => 'Colunas obrigatórias não encontradas na primeira linha: cnpj, uf.']);

        $this->post(self::URL.'/pdvs/importar', ['arquivo' => $this->csv([['CNPJ', 'Nome', 'Cidade', 'UF']])])
            ->assertSessionHasErrors(['arquivo' => 'A planilha está vazia.']);

        $this->post(self::URL.'/pdvs/importar', ['arquivo' => UploadedFile::fake()->create('lojas.pdf', 10)])
            ->assertSessionHasErrors('arquivo');
    }

    public function test_revisao_e_so_de_quem_enviou_e_pode_ser_descartada(): void
    {
        $tenant = $this->criarCliente('gaboardi');
        $agencia = $tenant->run(fn () => Agencia::create(['nome' => 'Agência']));
        $outroAdmin = $this->criarUsuario($tenant, Perfil::AdminAgencia, ['agencia_id' => $agencia->id]);

        $this->actingAs($this->admin($tenant));
        $resposta = $this->post(self::URL.'/pdvs/importar', ['arquivo' => $this->csv([
            ['CNPJ', 'Nome', 'Cidade', 'UF'],
            [self::cnpj(9), 'Loja', 'Caxias do Sul', 'RS'],
        ])]);
        $id = $this->revisaoId($resposta->headers->get('Location'));

        $this->actingAs($outroAdmin)->get(self::URL."/pdvs/importar/{$id}")->assertNotFound();
        $this->actingAs($outroAdmin)->post(self::URL."/pdvs/importar/{$id}", ['linhas' => [2]])->assertNotFound();
        $this->actingAs($outroAdmin)->get(self::URL.'/pdvs/importar/nao-existe')->assertNotFound();

        $this->actingAs($this->admin($tenant))
            ->post(self::URL."/pdvs/importar/{$id}", [])
            ->assertSessionHasErrors('linhas');

        $this->delete(self::URL."/pdvs/importar/{$id}")->assertRedirect(self::URL.'/pdvs/importar');
        $this->get(self::URL."/pdvs/importar/{$id}")->assertNotFound();

        $tenant->run(fn () => $this->assertSame(0, Pdv::count()));
    }

    public function test_job_de_geocoding_preenche_coordenadas_do_pdv_importado(): void
    {
        config(['trade.geocoding.google_key' => 'chave-teste']);
        Http::fake(['maps.googleapis.com/*' => Http::response([
            'status' => 'OK',
            'results' => [['geometry' => ['location' => ['lat' => -29.1, 'lng' => -51.1]]]],
        ])]);

        $tenant = $this->criarCliente('gaboardi');
        $this->actingAs($this->admin($tenant));

        // Fila síncrona nos testes: o job roda na gravação.
        $resposta = $this->post(self::URL.'/pdvs/importar', ['arquivo' => $this->csv([
            ['CNPJ', 'Nome', 'Logradouro', 'Cidade', 'UF'],
            [self::cnpj(9), 'Loja', 'Rua C', 'Caxias do Sul', 'RS'],
        ])]);
        $this->post(self::URL.'/pdvs/importar/'.$this->revisaoId($resposta->headers->get('Location')), ['linhas' => [2]]);

        $tenant->run(function () {
            $pdv = Pdv::sole();
            $this->assertEqualsWithDelta(-29.1, $pdv->lat, 1e-6);
            $this->assertSame('geocoding', $pdv->coordenadas_origem);
        });
    }
}
