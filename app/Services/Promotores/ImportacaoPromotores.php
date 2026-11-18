<?php

namespace App\Services\Promotores;

use App\Enums\TipoVinculo;
use App\Models\User;
use App\Rules\Cpf;
use App\Support\Estados;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use OpenSpout\Reader\CSV\Options as OpcoesCsv;
use OpenSpout\Reader\CSV\Reader as LeitorCsv;
use OpenSpout\Reader\XLSX\Reader as LeitorXlsx;

/**
 * Cadastro de promotores por planilha (CSV ou XLSX). Cada linha válida vira um promotor com
 * convite de primeiro acesso; linhas com erro ficam de fora e voltam num relatório. E-mail ou
 * CPF já cadastrados não são alterados: aparecem como erro.
 */
class ImportacaoPromotores
{
    public const MAXIMO_LINHAS = 500;

    private const COLUNAS = [
        'nome' => 'nome', 'promotor' => 'nome', 'nome_completo' => 'nome',
        'email' => 'email', 'e_mail' => 'email',
        'cpf' => 'cpf',
        'telefone' => 'telefone', 'celular' => 'telefone', 'whatsapp' => 'telefone', 'fone' => 'telefone',
        'vinculo' => 'tipo_vinculo', 'tipo_de_vinculo' => 'tipo_vinculo', 'tipo' => 'tipo_vinculo',
        'cidade' => 'cidade_base', 'cidade_base' => 'cidade_base', 'municipio' => 'cidade_base',
        'uf' => 'uf_base', 'estado' => 'uf_base', 'uf_base' => 'uf_base',
        'observacoes' => 'observacoes', 'observacao' => 'observacoes', 'obs' => 'observacoes',
    ];

    public function __construct(private Cadastro $cadastro) {}

    /**
     * @return array{criados: list<array{linha: int, nome: string, email: string, link: string}>, erros: list<array{linha: int, nome: string, motivos: list<string>}>}
     *
     * @throws ValidationException
     */
    public function importar(string $caminho, string $extensao, ?User $autor): array
    {
        $resultado = ['criados' => [], 'erros' => []];
        $emails = [];
        $cpfs = [];

        foreach ($this->ler($caminho, $extensao) as ['linha' => $linha, 'dados' => $dados]) {
            $dados = $this->normalizar($dados);
            $motivos = $this->validar($dados, $emails, $cpfs);
            if ($motivos) {
                $resultado['erros'][] = ['linha' => $linha, 'nome' => (string) ($dados['nome'] ?? ''), 'motivos' => $motivos];

                continue;
            }
            $emails[] = $dados['email'];
            $cpfs[] = Cpf::limpar($dados['cpf']);
            $criado = $this->cadastro->criar($dados, null, $autor, 'importado');
            $resultado['criados'][] = ['linha' => $linha, 'nome' => $dados['nome'], 'email' => $dados['email'], 'link' => $criado['convite']];
        }

        return $resultado;
    }

    /** @return array<string, mixed> */
    private function normalizar(array $bruto): array
    {
        $texto = fn (string $campo) => ($v = trim((string) ($bruto[$campo] ?? ''))) === '' ? null : $v;
        // CPF lido como número perde os zeros à esquerda.
        $cpf = $bruto['cpf'] ?? '';
        $cpf = is_int($cpf) || is_float($cpf) ? sprintf('%011.0f', $cpf) : Cpf::limpar((string) $cpf);
        $vinculo = Str::of((string) ($bruto['tipo_vinculo'] ?? ''))->ascii()->lower()->trim()->toString();

        return [
            'nome' => $texto('nome'),
            'email' => ($e = $texto('email')) === null ? null : Str::lower($e),
            'cpf' => $cpf === '' ? null : $cpf,
            'telefone' => $texto('telefone'),
            'tipo_vinculo' => $vinculo === '' ? 'clt' : ($vinculo === 'freelancer' || $vinculo === 'freela' ? 'freelancer' : ($vinculo === 'clt' ? 'clt' : $vinculo)),
            'cidade_base' => $texto('cidade_base'),
            'uf_base' => ($u = $texto('uf_base')) === null ? null : strtoupper($u),
            'observacoes' => $texto('observacoes'),
        ];
    }

    /** @return list<string> */
    private function validar(array $dados, array $emailsDoArquivo, array $cpfsDoArquivo): array
    {
        $v = Validator::make($dados, [
            'nome' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')],
            'cpf' => ['required', new Cpf],
            'telefone' => ['nullable', 'string', 'max:20'],
            'tipo_vinculo' => ['required', Rule::enum(TipoVinculo::class)],
            'cidade_base' => ['nullable', 'string', 'max:80'],
            'uf_base' => ['nullable', Rule::in(Estados::UFS)],
        ], [
            'email.unique' => 'E-mail já cadastrado.',
            'tipo_vinculo' => 'Vínculo deve ser CLT ou Freelancer.',
        ], ['cpf' => 'CPF', 'email' => 'e-mail', 'uf_base' => 'UF']);
        $motivos = $v->errors()->all();
        if ($dados['cpf'] && Cpf::valido($dados['cpf']) && User::where('cpf_hash', Cpf::hash($dados['cpf']))->exists()) {
            $motivos[] = 'CPF já cadastrado.';
        }
        if ($dados['email'] && in_array($dados['email'], $emailsDoArquivo, true)) {
            $motivos[] = 'E-mail repetido na planilha.';
        }
        if ($dados['cpf'] && in_array(Cpf::limpar($dados['cpf']), $cpfsDoArquivo, true)) {
            $motivos[] = 'CPF repetido na planilha.';
        }

        return array_values(array_unique($motivos));
    }

    /**
     * @return list<array{linha: int, dados: array<string, mixed>}>
     *
     * @throws ValidationException
     */
    private function ler(string $caminho, string $extensao): array
    {
        $leitor = strtolower($extensao) === 'xlsx' ? new LeitorXlsx : new LeitorCsv($this->opcoesCsv($caminho));
        $leitor->open($caminho);
        $mapa = null;
        $linhas = [];

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
                    $dados = [];
                    foreach ($mapa as $indice => $campo) {
                        $dados[$campo] = $celulas[$indice] ?? null;
                    }
                    if (collect($dados)->filter(fn ($v) => trim((string) $v) !== '')->isEmpty()) {
                        continue;
                    }
                    if (count($linhas) >= self::MAXIMO_LINHAS) {
                        throw ValidationException::withMessages(['arquivo' => 'A planilha passa de '.self::MAXIMO_LINHAS.' linhas. Divida em arquivos menores.']);
                    }
                    $linhas[] = ['linha' => $numero, 'dados' => $dados];
                }

                break; // só a primeira aba
            }
        } finally {
            $leitor->close();
        }
        if ($mapa === null || $linhas === []) {
            throw ValidationException::withMessages(['arquivo' => 'A planilha está vazia.']);
        }

        return $linhas;
    }

    /** @return array<int, string> */
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
        $faltando = array_diff(['nome', 'email', 'cpf'], $mapa);
        if ($faltando) {
            throw ValidationException::withMessages(['arquivo' => 'Colunas obrigatórias não encontradas na primeira linha: '.implode(', ', $faltando).'.']);
        }

        return $mapa;
    }

    private function opcoesCsv(string $caminho): OpcoesCsv
    {
        $inicio = (string) file_get_contents($caminho, false, null, 0, 8192);
        if (($fim = strrpos($inicio, "\n")) !== false) {
            $inicio = substr($inicio, 0, $fim);
        }
        $primeiraLinha = strtok($inicio, "\n") ?: '';
        $opcoes = new OpcoesCsv;
        $opcoes->FIELD_DELIMITER = substr_count($primeiraLinha, ';') > substr_count($primeiraLinha, ',') ? ';' : ',';
        $opcoes->ENCODING = mb_check_encoding($inicio, 'UTF-8') ? 'UTF-8' : 'Windows-1252';

        return $opcoes;
    }
}
