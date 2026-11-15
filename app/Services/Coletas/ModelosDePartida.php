<?php

namespace App\Services\Coletas;

/**
 * Modelos de partida (fase 10): campos prontos para cada tipo de coleta. A indústria cria o modelo a
 * partir de um deles e ajusta. Os campos "por SKU" são respondidos uma vez para cada produto.
 */
class ModelosDePartida
{
    /** @return array<string, array{nome: string, tipo: string, campos: list<array<string, mixed>>}> */
    public static function todos(): array
    {
        return [
            'preco' => ['nome' => 'Pesquisa de preço', 'tipo' => 'preco', 'campos' => [
                self::c('Preço de prateleira', 'preco', true, true),
                self::c('Preço promocional', 'preco', false, true),
                self::c('Concorrente principal (marca e produto)', 'texto'),
                self::c('Preço do concorrente', 'preco'),
                self::c('Concorrente em promoção?', 'sim_nao'),
            ]],
            'validade' => ['nome' => 'Validade (produtos a vencer)', 'tipo' => 'validade', 'campos' => [
                self::c('Validade', 'data', true, true),
                self::c('Lote', 'texto', false, true),
                self::c('Quantidade com esta validade', 'quantidade', true, true),
                self::c('Ação tomada', 'escolha', false, true, ['Retirado da gôndola', 'Trocado', 'Colocado em promoção', 'Nenhuma']),
            ]],
            'estoque' => ['nome' => 'Levantamento de estoque', 'tipo' => 'estoque', 'campos' => [
                self::c('Na gôndola (unidades)', 'quantidade', true, true),
                self::c('No estoque da loja (unidades)', 'quantidade', false, true),
                self::c('No depósito (unidades)', 'quantidade', false, true),
            ]],
            'share' => ['nome' => 'Pesquisa de share de gôndola', 'tipo' => 'share', 'campos' => [
                self::c('Frentes da nossa marca', 'quantidade', true),
                self::c('Frentes de concorrentes', 'quantidade', true),
                self::c('Categoria pesquisada', 'texto'),
            ]],
            'checklist' => ['nome' => 'Checklist de execução', 'tipo' => 'checklist', 'campos' => [
                self::c('Produtos no lugar certo?', 'sim_nao', true),
                self::c('Preços visíveis e corretos?', 'sim_nao', true),
                self::c('Material de ponto de venda instalado?', 'sim_nao'),
                self::c('Observações', 'texto'),
            ]],
        ];
    }

    /** @param  list<string>  $opcoes */
    private static function c(string $rotulo, string $tipo, bool $obrigatorio = false, bool $porSku = false, array $opcoes = []): array
    {
        return ['rotulo' => $rotulo, 'tipo' => $tipo, 'obrigatorio' => $obrigatorio, 'por_sku' => $porSku, 'opcoes' => $opcoes];
    }
}
