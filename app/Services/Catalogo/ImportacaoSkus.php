<?php

namespace App\Services\Catalogo;

use App\Enums\Perfil;
use App\Enums\TipoEmbalagem;
use App\Models\Industria;
use App\Models\Sku;
use App\Models\SkuEmbalagem;
use App\Models\User;
use App\Rules\Gtin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Importação do catálogo de SKUs em duas etapas, a partir de planilha (CSV/XLSX) ou de PDF:
 * 1) ler e analisar → arquivo de revisão na pasta do cliente; nada é gravado em `skus`;
 * 2) o usuário revisa e grava só os itens selecionados (atualiza pelo código, sem duplicar).
 *
 * Erro bloqueia a gravação do item. Aviso (GTIN com dígito errado, GTIN repetido, quantidade
 * suposta) não bloqueia: o item é gravado e o GTIN fica marcado para revisão no catálogo.
 */
class ImportacaoSkus
{
    private const PASTA = 'importacoes/skus';

    /**
     * Normaliza, une linhas do mesmo código e valida. Não grava nada.
     *
     * @param  list<array{linha: int, origem?: string, dados: array<string, mixed>, avisos?: list<string>}>  $itens
     * @return list<array{linha: int, origem: string, dados: array<string, mixed>, erros: list<string>, avisos: list<string>, acao: string, descricao_atual: ?string}>
     */
    public function analisar(int $industriaId, array $itens): array
    {
        $unidos = $this->unir($itens);

        $existentes = Sku::where('industria_id', $industriaId)
            ->whereIn('codigo', array_filter(array_map(fn ($i) => $i['dados']['codigo'], $unidos)))
            ->pluck('descricao', 'codigo');

        $gtinsExistentes = $this->gtinsDeOutrosSkus($industriaId, $unidos);
        $gtinsNoArquivo = [];
        $resultado = [];

        foreach ($unidos as $item) {
            $dados = $item['dados'];
            $erros = $this->validar($dados);
            $avisos = $item['avisos'];

            foreach ($dados['embalagens'] as $embalagem) {
                $gtin = $embalagem['gtin'];
                if ($gtin === null || ! Gtin::formatoValido($gtin)) {
                    continue;
                }
                if (! Gtin::digitoCorreto($gtin)) {
                    $avisos[] = "GTIN {$gtin} com dígito verificador errado (será marcado para revisão).";
                }
                if (isset($gtinsNoArquivo[$gtin]) && $gtinsNoArquivo[$gtin] !== $dados['codigo']) {
                    $avisos[] = "GTIN {$gtin} repetido no arquivo (também em {$gtinsNoArquivo[$gtin]}).";
                }
                $gtinsNoArquivo[$gtin] ??= $dados['codigo'];

                foreach ($gtinsExistentes[$gtin] ?? [] as $outro) {
                    $avisos[] = "GTIN {$gtin} já cadastrado no SKU {$outro}.";
                }
            }

            $atual = $existentes[$dados['codigo']] ?? null;
            if ($atual !== null && $atual !== $dados['descricao'] && $dados['descricao'] !== '') {
                $avisos[] = "Descrição cadastrada hoje: «{$atual}». Será substituída.";
            }

            $resultado[] = [
                'linha' => $item['linha'],
                'origem' => $item['origem'],
                'dados' => $dados,
                'erros' => $erros,
                'avisos' => array_values(array_unique($avisos)),
                'acao' => $erros ? 'erro' : ($atual !== null ? 'atualizar' : 'novo'),
                'descricao_atual' => $atual,
            ];
        }

        return $resultado;
    }

    /**
     * Grava os itens escolhidos. Itens com erro são ignorados.
     * Na atualização, o que veio vazio (categoria, GTIN, código) mantém o valor atual, e uma
     * quantidade que o arquivo só supôs não substitui a quantidade já cadastrada.
     *
     * @param  list<array{linha: int, origem?: string, dados: array<string, mixed>}>  $itens
     * @return array{criados: int, atualizados: int, ignorados: int, com_alerta_de_gtin: int}
     */
    public function gravar(int $industriaId, array $itens): array
    {
        // Reanalisa: o banco pode ter mudado desde a leitura.
        $analisados = $this->analisar($industriaId, $itens);

        $contagem = ['criados' => 0, 'atualizados' => 0, 'ignorados' => 0, 'com_alerta_de_gtin' => 0];

        DB::transaction(function () use ($industriaId, $analisados, &$contagem) {
            foreach ($analisados as $item) {
                if ($item['erros']) {
                    $contagem['ignorados']++;

                    continue;
                }

                $dados = $item['dados'];
                $sku = Sku::firstOrNew(['industria_id' => $industriaId, 'codigo' => $dados['codigo']]);
                $novo = ! $sku->exists;

                $sku->descricao = $dados['descricao'];
                if ($dados['categoria'] !== null) {
                    $sku->categoria = $dados['categoria'];
                }
                if ($novo) {
                    $sku->ativo = true;
                }
                $sku->save();

                $this->gravarEmbalagens($sku, $dados['embalagens']);

                $contagem[$novo ? 'criados' : 'atualizados']++;

                if ($sku->embalagens()->where('gtin_valido', false)->exists()) {
                    $contagem['com_alerta_de_gtin']++;
                }
            }
        });

        return $contagem;
    }

    /**
     * Guarda a análise para a tela de revisão e devolve o identificador.
     *
     * @param  'planilha'|'pdf'  $fonte
     */
    public function salvarRevisao(User $usuario, int $industriaId, string $fonte, string $nomeArquivo, array $itens): string
    {
        $id = (string) Str::uuid();

        Storage::disk('local')->put(self::PASTA."/{$id}.json", json_encode([
            'usuario_id' => $usuario->id,
            'industria_id' => $industriaId,
            'fonte' => $fonte,
            'arquivo' => $nomeArquivo,
            'criada_em' => now()->toIso8601String(),
            'itens' => $itens,
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));

        return $id;
    }

    /** @return array{usuario_id: int, industria_id: int, fonte: string, arquivo: string, criada_em: string, itens: list<array>}|null */
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

    /** Indústrias em que o usuário pode importar: o admin escolhe; o gerente de trade só a própria. */
    public function industriasDoUsuario(User $usuario)
    {
        return Industria::where('ativo', true)
            ->when(! $usuario->temPerfil(Perfil::AdminInstalacao), fn ($q) => $q->whereKey($usuario->industria_id ?? 0))
            ->orderBy('nome')
            ->pluck('nome', 'id');
    }

    /**
     * Normaliza cada item e une os que têm o mesmo código (formato longo da planilha).
     *
     * @return list<array{linha: int, origem: string, dados: array<string, mixed>, avisos: list<string>}>
     */
    private function unir(array $itens): array
    {
        $unidos = [];
        $posicao = [];

        foreach ($itens as $item) {
            $dados = $this->normalizar($item['dados']);
            $avisos = array_values($item['avisos'] ?? []);
            $registro = [
                'linha' => (int) $item['linha'],
                'origem' => (string) ($item['origem'] ?? 'Linha '.$item['linha']),
                'dados' => $dados,
                'avisos' => $avisos,
            ];

            $codigo = $dados['codigo'];
            if ($codigo === '' || ! isset($posicao[mb_strtolower($codigo)])) {
                $posicao[mb_strtolower($codigo)] = count($unidos);
                $unidos[] = $registro;

                continue;
            }

            $alvo = &$unidos[$posicao[mb_strtolower($codigo)]];
            if ($dados['descricao'] !== '' && $alvo['dados']['descricao'] === '') {
                $alvo['dados']['descricao'] = $dados['descricao'];
            } elseif ($dados['descricao'] !== '' && $dados['descricao'] !== $alvo['dados']['descricao']) {
                $alvo['avisos'][] = "Descrição diferente no mesmo código ({$registro['origem']}); ficou a primeira.";
            }
            $alvo['dados']['categoria'] ??= $dados['categoria'];
            foreach ($dados['embalagens'] as $embalagem) {
                $repetida = collect($alvo['dados']['embalagens'])->contains(
                    fn ($e) => $e['tipo'] === $embalagem['tipo'] && $e['gtin'] === $embalagem['gtin'] && $e['quantidade'] === $embalagem['quantidade']
                );
                if (! $repetida) {
                    $alvo['dados']['embalagens'][] = $embalagem;
                }
            }
            $alvo['avisos'][] = "Linhas do mesmo código foram unidas ({$registro['origem']}).";
            unset($alvo);
        }

        return $unidos;
    }

    /** @return array<string, mixed> */
    private function normalizar(array $bruto): array
    {
        $texto = fn (mixed $v) => trim((string) preg_replace('/\s+/u', ' ', (string) $v));

        $embalagens = [];
        foreach ((array) ($bruto['embalagens'] ?? []) as $e) {
            $gtin = Gtin::normalizar((string) ($e['gtin'] ?? ''));
            $codigo = $texto($e['codigo'] ?? '');
            $embalagens[] = [
                'tipo' => $texto($e['tipo'] ?? ''),
                'quantidade' => is_numeric($e['quantidade'] ?? null) ? (int) $e['quantidade'] : ($e['quantidade'] ?? null),
                'quantidade_conhecida' => (bool) ($e['quantidade_conhecida'] ?? true),
                'gtin' => $gtin === '' ? null : $gtin,
                'codigo' => $codigo === '' ? null : $codigo,
            ];
        }

        return [
            'codigo' => $texto($bruto['codigo'] ?? ''),
            'descricao' => $texto($bruto['descricao'] ?? ''),
            'categoria' => ($c = $texto($bruto['categoria'] ?? '')) === '' ? null : $c,
            'embalagens' => $embalagens,
        ];
    }

    /** @return list<string> */
    private function validar(array $dados): array
    {
        $validador = Validator::make($dados, [
            'codigo' => ['required', 'string', 'max:40'],
            'descricao' => ['required', 'string', 'max:200'],
            'categoria' => ['nullable', 'string', 'max:80'],
            'embalagens' => ['array', 'max:20'],
            'embalagens.*.tipo' => ['required', 'in:'.implode(',', array_keys(TipoEmbalagem::opcoes()))],
            'embalagens.*.quantidade' => ['required', 'integer', 'min:1', 'max:100000'],
            'embalagens.*.gtin' => ['nullable', 'string', 'max:20', new Gtin],
            'embalagens.*.codigo' => ['nullable', 'string', 'max:40'],
        ], [
            'embalagens.*.tipo.in' => 'Tipo de embalagem desconhecido. Use unidade, reembalagem ou caixa master.',
            'embalagens.*.tipo.required' => 'Tipo de embalagem desconhecido. Use unidade, reembalagem ou caixa master.',
            'embalagens.max' => 'No máximo 20 embalagens por produto.',
        ], [
            'codigo' => 'código',
            'descricao' => 'descrição',
            'embalagens.*.gtin' => 'GTIN',
            'embalagens.*.quantidade' => 'quantidade da embalagem',
            'embalagens.*.codigo' => 'código da embalagem',
        ]);

        return $validador->errors()->all();
    }

    /**
     * GTINs do arquivo que já existem em outros SKUs da mesma indústria.
     *
     * @return array<string, list<string>> gtin => códigos dos outros SKUs
     */
    private function gtinsDeOutrosSkus(int $industriaId, array $itens): array
    {
        $mapa = [];
        foreach ($itens as $item) {
            foreach ($item['dados']['embalagens'] as $e) {
                if ($e['gtin'] !== null && Gtin::formatoValido($e['gtin'])) {
                    $mapa[$e['gtin']][] = $item['dados']['codigo'];
                }
            }
        }
        if ($mapa === []) {
            return [];
        }

        $resultado = [];
        SkuEmbalagem::whereIn('gtin', array_keys($mapa))
            ->whereHas('sku', fn ($q) => $q->where('industria_id', $industriaId))
            ->with('sku:id,codigo')
            ->get()
            ->each(function (SkuEmbalagem $e) use ($mapa, &$resultado) {
                if (! in_array($e->sku->codigo, $mapa[$e->gtin], true)) {
                    $resultado[$e->gtin][] = $e->sku->codigo;
                }
            });

        return $resultado;
    }

    /**
     * Cria ou atualiza as embalagens do SKU. Não apaga embalagens que o arquivo não traz.
     *
     * @param  list<array<string, mixed>>  $embalagens
     */
    private function gravarEmbalagens(Sku $sku, array $embalagens): void
    {
        $atuais = $sku->embalagens()->get();
        $usadas = [];

        foreach ($embalagens as $nova) {
            $alvo = $atuais->first(fn ($e) => ! in_array($e->id, $usadas, true) && $nova['gtin'] !== null && $e->gtin === $nova['gtin']);

            if (! $alvo) {
                $mesmoTipo = $atuais->filter(fn ($e) => ! in_array($e->id, $usadas, true) && $e->tipo->value === $nova['tipo']);
                $alvo = $mesmoTipo->count() === 1
                    ? $mesmoTipo->first()
                    : $mesmoTipo->first(fn ($e) => $e->quantidade === (int) $nova['quantidade']);
            }

            $embalagem = $alvo ?? $sku->embalagens()->make();
            $embalagem->tipo = $nova['tipo'];
            if ($alvo === null || $nova['quantidade_conhecida']) {
                $embalagem->quantidade = (int) $nova['quantidade'];
            }
            if ($nova['gtin'] !== null) {
                $embalagem->gtin = $nova['gtin'];
            }
            if ($nova['codigo'] !== null) {
                $embalagem->codigo = $nova['codigo'];
            }
            $sku->embalagens()->save($embalagem);
            $usadas[] = $embalagem->id;
        }
    }
}
