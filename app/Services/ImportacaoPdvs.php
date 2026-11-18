<?php

namespace App\Services;

use App\Enums\CanalPdv;
use App\Jobs\GeocodificarPdv;
use App\Models\Pdv;
use App\Models\Rede;
use App\Models\Regiao;
use App\Models\User;
use App\Rules\Cnpj;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenSpout\Reader\CSV\Options as OpcoesCsv;
use OpenSpout\Reader\CSV\Reader as LeitorCsv;
use OpenSpout\Reader\XLSX\Reader as LeitorXlsx;

/**
 * Importação de PDVs por planilha (CSV ou XLSX) em duas etapas:
 * 1) ler e analisar → arquivo de revisão na pasta do cliente;
 * 2) o usuário revisa e grava só as linhas selecionadas (atualiza pelo CNPJ).
 */
class ImportacaoPdvs
{
    /** Cabeçalhos aceitos (normalizados) → campo. */
    private const COLUNAS = [
        'cnpj' => 'cnpj',
        'nome' => 'nome', 'loja' => 'nome', 'pdv' => 'nome', 'nome_da_loja' => 'nome', 'nome_loja' => 'nome',
        'nome_do_pdv' => 'nome', 'nome_fantasia' => 'nome', 'razao_social' => 'nome',
        'rede' => 'rede', 'bandeira' => 'rede',
        'canal' => 'canal',
        'cep' => 'cep',
        'logradouro' => 'logradouro', 'endereco' => 'logradouro', 'rua' => 'logradouro',
        'numero' => 'numero', 'num' => 'numero', 'no' => 'numero', 'n' => 'numero',
        'complemento' => 'complemento',
        'bairro' => 'bairro',
        'cidade' => 'cidade', 'municipio' => 'cidade',
        'uf' => 'uf', 'estado' => 'uf',
        'lat' => 'lat', 'latitude' => 'lat',
        'lng' => 'lng', 'lon' => 'lng', 'long' => 'lng', 'longitude' => 'lng',
        'raio' => 'raio_m', 'raio_m' => 'raio_m', 'raio_checkin' => 'raio_m', 'raio_de_checkin' => 'raio_m',
        'regiao' => 'regiao',
    ];

    private const PASTA = 'importacoes/pdvs';

    /**
     * Lê a planilha e devolve as linhas com o número da linha no arquivo.
     *
     * @return list<array{linha: int, dados: array<string, mixed>}>
     *
     * @throws ValidationException
     */
    public function ler(string $caminho, string $extensao): array
    {
        $leitor = strtolower($extensao) === 'xlsx' ? new LeitorXlsx : new LeitorCsv($this->opcoesCsv($caminho));
        $leitor->open($caminho);

        $mapa = null;
        $linhas = [];
        $maximo = (int) config('trade.importacao_pdvs.max_linhas');

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

                    if (count($linhas) >= $maximo) {
                        throw ValidationException::withMessages([
                            'arquivo' => "A planilha passa de {$maximo} linhas. Divida em arquivos menores.",
                        ]);
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

    /**
     * Normaliza e valida cada linha. Não grava nada.
     *
     * @param  list<array{linha: int, dados: array<string, mixed>}>  $linhas
     * @return list<array{linha: int, dados: array<string, mixed>, erros: list<string>, acao: string}>
     */
    public function analisar(array $linhas): array
    {
        $normalizadas = array_map(fn ($l) => ['linha' => $l['linha'], 'dados' => $this->normalizar($l['dados'])], $linhas);

        $existentes = Pdv::whereIn('cnpj', array_filter(array_column(array_column($normalizadas, 'dados'), 'cnpj')))
            ->pluck('id', 'cnpj');

        $vistos = [];
        $resultado = [];

        foreach ($normalizadas as $linha) {
            $dados = $linha['dados'];
            $erros = $this->validar($dados);

            if ($dados['cnpj'] !== '' && isset($vistos[$dados['cnpj']])) {
                $erros[] = "CNPJ repetido na planilha (linha {$vistos[$dados['cnpj']]}).";
            }
            $vistos[$dados['cnpj']] ??= $linha['linha'];

            $resultado[] = [
                'linha' => $linha['linha'],
                'dados' => $dados,
                'erros' => $erros,
                'acao' => $erros ? 'erro' : (isset($existentes[$dados['cnpj']]) ? 'atualizar' : 'novo'),
            ];
        }

        return $resultado;
    }

    /**
     * Grava as linhas escolhidas. Linhas com erro são ignoradas.
     * Na atualização, célula vazia mantém o valor atual do PDV.
     *
     * @param  list<array{linha: int, dados: array<string, mixed>, erros: list<string>, acao: string}>  $linhas
     * @return array{criados: int, atualizados: int, ignorados: int}
     */
    public function gravar(array $linhas): array
    {
        // Reanalisa: o banco pode ter mudado desde a leitura.
        $linhas = $this->analisar(array_map(fn ($l) => ['linha' => $l['linha'], 'dados' => $l['dados']], $linhas));

        $contagem = ['criados' => 0, 'atualizados' => 0, 'ignorados' => 0];
        $semCoordenadas = [];

        DB::transaction(function () use ($linhas, &$contagem, &$semCoordenadas) {
            $redes = [];
            $regioes = [];

            foreach ($linhas as $linha) {
                if ($linha['erros']) {
                    $contagem['ignorados']++;

                    continue;
                }

                $dados = $linha['dados'];
                $pdv = Pdv::firstOrNew(['cnpj' => $dados['cnpj']]);
                $novo = ! $pdv->exists;

                $campos = collect($dados)
                    ->except(['rede', 'regiao'])
                    ->reject(fn ($v) => $v === null || $v === '')
                    ->all();

                if ($dados['rede']) {
                    $campos['rede_id'] = $redes[mb_strtolower($dados['rede'])] ??= $this->porNome(Rede::class, $dados['rede'])->id;
                }
                if ($dados['regiao']) {
                    $campos['regiao_id'] = $regioes[mb_strtolower($dados['regiao'])] ??= $this->porNome(Regiao::class, $dados['regiao'])->id;
                }
                if (isset($campos['lat'], $campos['lng'])) {
                    $campos['coordenadas_origem'] = 'planilha';
                }

                $pdv->fill($campos);
                if ($novo) {
                    $pdv->raio_m ??= Pdv::RAIO_PADRAO;
                    $pdv->ativo = true;
                }
                $pdv->save();

                $contagem[$novo ? 'criados' : 'atualizados']++;

                if (! $pdv->temCoordenadas()) {
                    $semCoordenadas[] = $pdv->id;
                }
            }
        });

        foreach ($semCoordenadas as $id) {
            GeocodificarPdv::dispatch($id);
        }

        return $contagem;
    }

    /** Guarda a análise para a tela de revisão e devolve o identificador. */
    public function salvarRevisao(User $usuario, string $nomeArquivo, array $linhas): string
    {
        $id = (string) Str::uuid();

        Storage::disk('local')->put(self::PASTA."/{$id}.json", json_encode([
            'usuario_id' => $usuario->id,
            'arquivo' => $nomeArquivo,
            'criada_em' => now()->toIso8601String(),
            'linhas' => $linhas,
        ], JSON_UNESCAPED_UNICODE));

        return $id;
    }

    /** @return array{usuario_id: int, arquivo: string, criada_em: string, linhas: list<array>}|null */
    public function carregarRevisao(string $id, User $usuario): ?array
    {
        if (! Str::isUuid($id) || ! Storage::disk('local')->exists(self::PASTA."/{$id}.json")) {
            return null;
        }

        $revisao = json_decode(Storage::disk('local')->get(self::PASTA."/{$id}.json"), true);

        return ($revisao['usuario_id'] ?? null) === $usuario->id ? $revisao : null;
    }

    public function descartarRevisao(string $id): void
    {
        Storage::disk('local')->delete(self::PASTA."/{$id}.json");
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

        $faltando = array_diff(['cnpj', 'nome', 'cidade', 'uf'], $mapa);
        if ($faltando) {
            throw ValidationException::withMessages([
                'arquivo' => 'Colunas obrigatórias não encontradas na primeira linha: '.implode(', ', $faltando).'.',
            ]);
        }

        return $mapa;
    }

    private function normalizar(array $bruto): array
    {
        $texto = fn (string $campo) => ($v = trim((string) ($bruto[$campo] ?? ''))) === '' ? null : $v;

        // CNPJ lido como número perde os zeros à esquerda.
        $cnpj = $bruto['cnpj'] ?? '';
        $cnpj = is_int($cnpj) || is_float($cnpj) ? sprintf('%014.0f', $cnpj) : Cnpj::limpar((string) $cnpj);

        $numero = function (string $campo) use ($bruto): ?float {
            $valor = $bruto[$campo] ?? null;
            if ($valor === null || trim((string) $valor) === '') {
                return null;
            }

            return is_numeric($v = str_replace(',', '.', trim((string) $valor))) ? (float) $v : NAN;
        };

        $cep = $texto('cep');
        $cep = $cep !== null ? str_pad(preg_replace('/\D/', '', $cep), 8, '0', STR_PAD_LEFT) : null;

        $canalTexto = $texto('canal');
        $canal = CanalPdv::deTexto($canalTexto);

        $raio = $numero('raio_m');

        return [
            'cnpj' => $cnpj,
            'nome' => $texto('nome'),
            'rede' => $texto('rede'),
            'regiao' => $texto('regiao'),
            'canal' => $canal?->value ?? $canalTexto,
            'cep' => $cep,
            'logradouro' => $texto('logradouro'),
            'numero' => $texto('numero'),
            'complemento' => $texto('complemento'),
            'bairro' => $texto('bairro'),
            'cidade' => $texto('cidade'),
            'uf' => ($uf = $texto('uf')) !== null ? mb_strtoupper($uf) : null,
            'lat' => is_float($lat = $numero('lat')) && is_nan($lat) ? 'inválida' : $lat,
            'lng' => is_float($lng = $numero('lng')) && is_nan($lng) ? 'inválida' : $lng,
            'raio_m' => $raio === null ? null : (is_nan($raio) ? 'inválido' : (fmod($raio, 1.0) == 0.0 ? (int) $raio : $raio)),
        ];
    }

    /** @return list<string> */
    private function validar(array $dados): array
    {
        $validador = Validator::make($dados, [
            'cnpj' => ['required', new Cnpj],
            'nome' => ['required', 'string', 'max:150'],
            'rede' => ['nullable', 'string', 'max:80'],
            'regiao' => ['nullable', 'string', 'max:80'],
            'canal' => ['nullable', 'in:'.implode(',', array_keys(CanalPdv::opcoes()))],
            'cep' => ['nullable', 'digits:8'],
            'logradouro' => ['nullable', 'string', 'max:150'],
            'numero' => ['nullable', 'max:20'],
            'complemento' => ['nullable', 'string', 'max:80'],
            'bairro' => ['nullable', 'string', 'max:80'],
            'cidade' => ['required', 'string', 'max:80'],
            'uf' => ['required', 'string', 'size:2', 'alpha'],
            'lat' => ['nullable', 'required_with:lng', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'required_with:lat', 'numeric', 'between:-180,180'],
            'raio_m' => ['nullable', 'integer', 'between:'.Pdv::RAIO_MINIMO.','.Pdv::RAIO_MAXIMO],
        ], [
            'canal.in' => 'Canal desconhecido: :input.',
            'raio_m.between' => 'O raio de check-in deve ficar entre '.Pdv::RAIO_MINIMO.' e '.Pdv::RAIO_MAXIMO.' metros.',
            'raio_m.integer' => 'O raio de check-in deve ser um número inteiro de metros.',
        ], [
            'cnpj' => 'CNPJ',
            'uf' => 'UF',
            'cep' => 'CEP',
        ]);

        return $validador->errors()->all();
    }

    /**
     * @param  class-string<Model>  $modelo
     */
    private function porNome(string $modelo, string $nome): Model
    {
        return $modelo::whereRaw('lower(nome) = ?', [mb_strtolower($nome)])->first()
            ?? $modelo::create(['nome' => $nome, 'ativo' => true]);
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
