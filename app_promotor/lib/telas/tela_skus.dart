import 'package:flutter/material.dart';

import '../modelos/execucao.dart';
import '../modelos/roteiro.dart';

/// Seleção dos SKUs da indústria. No modo normal, cada SKU marcado exige a
/// situação de estoque; no modo ruptura, basta marcar os produtos em falta.
class TelaSkus extends StatefulWidget {
  const TelaSkus({super.key, required this.industria, required this.iniciais, this.ruptura = false});

  final Industria industria;
  final List<ItemTrabalhado> iniciais;
  final bool ruptura;

  @override
  State<TelaSkus> createState() => _TelaSkusState();
}

class _TelaSkusState extends State<TelaSkus> {
  late final Map<int, SituacaoEstoque?> _selecionados = {for (final i in widget.iniciais) i.skuId: i.estoque};
  String _busca = '';

  List<Sku> get _filtrados {
    final termo = _busca.trim().toLowerCase();
    if (termo.isEmpty) {
      return widget.industria.skus;
    }

    return widget.industria.skus
        .where((s) =>
            s.codigo.toLowerCase().contains(termo) ||
            s.descricao.toLowerCase().contains(termo) ||
            (s.categoria?.toLowerCase().contains(termo) ?? false) ||
            s.embalagens.any((e) => e.gtin?.contains(termo) ?? false))
        .toList();
  }

  void _concluir() {
    if (!widget.ruptura && _selecionados.values.any((v) => v == null)) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Informe a situação de estoque de cada SKU marcado.')),
      );

      return;
    }
    Navigator.of(context).pop(
      _selecionados.entries.map((e) => ItemTrabalhado(skuId: e.key, estoque: e.value)).toList(),
    );
  }

  @override
  Widget build(BuildContext context) {
    final lista = _filtrados;

    return Scaffold(
      appBar: AppBar(
        title: Text(widget.ruptura ? 'Produtos em ruptura' : 'SKUs trabalhados'),
        actions: [
          TextButton(
            onPressed: _concluir,
            child: Text('Concluir (${_selecionados.length})'),
          ),
        ],
        bottom: PreferredSize(
          preferredSize: const Size.fromHeight(60),
          child: Padding(
            padding: const EdgeInsets.fromLTRB(12, 0, 12, 8),
            child: TextField(
              decoration: const InputDecoration(
                hintText: 'Buscar por código, descrição ou GTIN',
                prefixIcon: Icon(Icons.search),
                border: OutlineInputBorder(),
                isDense: true,
              ),
              onChanged: (v) => setState(() => _busca = v),
            ),
          ),
        ),
      ),
      body: ListView.builder(
        itemCount: lista.length,
        itemBuilder: (context, i) {
          final sku = lista[i];
          final marcado = _selecionados.containsKey(sku.id);

          return Column(
            children: [
              CheckboxListTile(
                value: marcado,
                title: Text('${sku.codigo} · ${sku.descricao}'),
                subtitle: sku.categoria == null ? null : Text(sku.categoria!),
                controlAffinity: ListTileControlAffinity.leading,
                onChanged: (v) => setState(() {
                  if (v == true) {
                    _selecionados[sku.id] = widget.ruptura ? null : _selecionados[sku.id];
                  } else {
                    _selecionados.remove(sku.id);
                  }
                }),
              ),
              if (marcado && !widget.ruptura)
                Padding(
                  padding: const EdgeInsets.fromLTRB(56, 0, 16, 8),
                  child: SegmentedButton<SituacaoEstoque>(
                    segments: [
                      for (final s in SituacaoEstoque.values) ButtonSegment(value: s, label: Text(s.rotulo)),
                    ],
                    emptySelectionAllowed: true,
                    selected: {if (_selecionados[sku.id] != null) _selecionados[sku.id]!},
                    onSelectionChanged: (v) => setState(() => _selecionados[sku.id] = v.isEmpty ? null : v.first),
                  ),
                ),
              const Divider(height: 1),
            ],
          );
        },
      ),
    );
  }
}
