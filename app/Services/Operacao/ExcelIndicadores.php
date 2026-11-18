<?php

namespace App\Services\Operacao;

use App\Models\User;
use App\Models\VisitaSku;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Excel dos indicadores (resumo, visitas, produção, SKUs, cobertura, rupturas, demandas e tempo),
 * com os mesmos filtros da tela. Usado direto pela tela e pela fila de downloads. Textos entram como
 * texto, sem execução de fórmulas.
 */
class ExcelIndicadores
{
    public function __construct(private Indicadores $s) {}

    /** @param  array<string, mixed>  $f  filtros já validados */
    public function gravar(User $u, array $f, string $arquivo): void
    {
        $s = $this->s;
        $writer = new Writer;
        $linha = fn (array $valores) => new Row(array_map(fn ($v) => is_string($v) ? new StringCell($v, null) : Cell::fromValue($v), $valores));
        try {
            $writer->openToFile($arquivo);
            $writer->getCurrentSheet()->setName('Resumo');
            $writer->addRow($linha(['Período', $f['inicio'].' a '.$f['fim']]));
            $rotulos = ['previstas' => 'Visitas contratuais previstas e geradas', 'cobertas' => 'Visitas contratuais realizadas', 'cobertura' => 'Cobertura dos contratos (%)', 'visitas' => 'Visitas na agenda', 'realizadas' => 'Visitas realizadas por conclusão', 'nao_executadas' => 'Visitas não executadas', 'impedimentos' => 'Impedimentos de acesso', 'tempo_medio_min' => 'Tempo médio de execução (min)', 'skus_trabalhados' => 'SKUs trabalhados', 'rupturas' => 'Rupturas de SKU registradas em visita', 'demandas' => 'Demandas abertas', 'demandas_no_prazo' => 'Demandas atendidas no prazo', 'demandas_atrasadas' => 'Demandas atrasadas', 'relatos_ruptura' => 'Relatos de ruptura recebidos'];
            foreach ($s->resumo($u, $f) as $nome => $valor) {
                $writer->addRow($linha([$rotulos[$nome], $valor]));
            }
            $writer->addRow($linha(['Base cobertura', 'Visitas contratuais já geradas, por data prevista; produção por conclusão.']));
            $writer->addNewSheetAndMakeItCurrent()->setName('Visitas');
            $writer->addRow($linha(['ID', 'Dia previsto', 'PDV', 'Indústria', 'Agência', 'Promotor', 'Status', 'Conclusão', 'Tempo (min)', 'Observação']));
            foreach ($s->visitas($u, $f)->whereDate('data', '>=', $f['inicio'])->whereDate('data', '<=', $f['fim'])->with(['pdv', 'industria', 'agencia', 'roteiro'])->orderBy('id')->lazyById(100) as $v) {
                $writer->addRow($linha([$v->id, $v->data->toDateString(), $v->pdv->nome, $v->industria->nome, $v->agencia->nome, $v->nomePromotor(), $v->status->label(), $v->concluida_em?->format('d/m/Y H:i:s'), $v->duracao_segundos === null ? null : round($v->duracao_segundos / 60, 1), $v->observacao]));
            }
            $writer->addNewSheetAndMakeItCurrent()->setName('Produção SKU por dia');
            $writer->addRow($linha(['Dia de conclusão', 'Promotor', 'SKUs trabalhados']));
            foreach ($s->producao($u, $f) as $p) {
                $writer->addRow($linha([$p->dia, $p->promotor, (int) $p->total]));
            }
            $writer->addNewSheetAndMakeItCurrent()->setName('SKUs por visita');
            $writer->addRow($linha(['Dia de conclusão', 'Visita', 'Promotor', 'PDV', 'Indústria', 'Código do SKU', 'Produto', 'Estoque']));
            foreach (VisitaSku::whereIn('visita_id', $s->execucoes($u, $f)->select('id'))->with(['sku', 'visita.pdv', 'visita.industria', 'visita.roteiro'])->lazyById(100) as $item) {
                $v = $item->visita;
                $writer->addRow($linha([$v->concluida_em->format('d/m/Y'), $v->id, $v->nomePromotor(), $v->pdv->nome, $v->industria->nome, $item->sku->codigo, $item->sku->descricao, ['tem_estoque' => 'Tem estoque', 'somente_gondola' => 'Estoque somente na gôndola', 'ruptura' => 'Ruptura'][$item->situacao_estoque]]));
            }
            $writer->addNewSheetAndMakeItCurrent()->setName('Cobertura de contratos');
            $writer->addRow($linha(['Visita', 'Dia previsto no contrato', 'Dia agendado', 'PDV', 'Indústria', 'Agência', 'Situação']));
            foreach ($s->visitas($u, $f)->where('origem', 'contrato')->whereDate('data_prevista', '>=', $f['inicio'])->whereDate('data_prevista', '<=', $f['fim'])->with(['pdv', 'industria', 'agencia'])->lazyById(100) as $v) {
                $writer->addRow($linha([$v->id, $v->data_prevista->toDateString(), $v->data->toDateString(), $v->pdv->nome, $v->industria->nome, $v->agencia->nome, $v->status->label()]));
            }
            $writer->addNewSheetAndMakeItCurrent()->setName('Rupturas');
            $writer->addRow($linha(['SKU', 'Produto', 'PDV', 'Ocorrências']));
            foreach ($s->rupturas($u, $f)->cursor() as $p) {
                $writer->addRow($linha([$p->codigo, $p->descricao, $p->pdv, (int) $p->total]));
            }
            $writer->addNewSheetAndMakeItCurrent()->setName('Demandas');
            $writer->addRow($linha(['ID', 'PDV', 'Indústria', 'Agência', 'Tipo', 'Status', 'Prazo', 'Atendimento', 'Atrasada']));
            foreach ($s->demandas($u, $f)->with(['pdv', 'industria', 'agencia'])->orderBy('id')->lazyById(100) as $d) {
                $writer->addRow($linha([$d->id, $d->pdv->nome, $d->industria->nome, $d->agencia->nome, $d->tipo, $d->status, $d->prazo_em->format('d/m/Y H:i'), $d->atendida_em?->format('d/m/Y H:i'), $d->atrasada() ? 'Sim' : 'Não']));
            }
            $writer->addNewSheetAndMakeItCurrent()->setName('Tempo e impedimentos');
            $writer->addRow($linha(['PDV', 'Promotor', 'Conclusões', 'Impedimentos', 'Tempo médio realizado (min)']));
            foreach ($s->tempos($u, $f) as $p) {
                $writer->addRow($linha([$p->pdv, $p->promotor, (int) $p->total, (int) $p->impedimentos, $p->minutos === null ? null : round($p->minutos, 1)]));
            }
            $writer->close();
        } catch (\Throwable $e) {
            try {
                $writer->close();
            } catch (\Throwable) {
            }
            @unlink($arquivo);
            throw $e;
        }
    }
}
