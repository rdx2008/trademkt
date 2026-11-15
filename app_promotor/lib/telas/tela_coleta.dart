import 'package:flutter/material.dart';

import '../estado_app.dart';
import '../modelos/coleta.dart';
import '../modelos/roteiro.dart';

/// Formulário de um modelo de coleta (fase 10): perguntas gerais e, quando o modelo pede, as perguntas
/// por produto. Funciona sem internet: ao salvar, as respostas entram na fila de envio.
class TelaColeta extends StatefulWidget {
  const TelaColeta({super.key, required this.estado, required this.visita, required this.modelo, required this.industria});

  final EstadoApp estado;
  final Visita visita;
  final ModeloColeta modelo;
  final Industria? industria;

  @override
  State<TelaColeta> createState() => _TelaColetaState();
}

class _TelaColetaState extends State<TelaColeta> {
  final Map<String, Object?> _gerais = {};
  final Map<int, Map<String, Object?>> _porSku = {};
  final Set<int> _enviados = {};
  bool _geralEnviado = false;
  bool _salvando = false;
  String _busca = '';

  @override
  void initState() {
    super.initState();
    final e = widget.estado.execucao(widget.visita.chave);
    for (final r in e.coletas.where((c) => c.modeloId == widget.modelo.id)) {
      if (r.skuId == null) {
        _gerais.addAll(r.respostas);
        _geralEnviado = r.enviada;
      } else {
        _porSku[r.skuId!] = {...r.respostas};
        if (r.enviada) {
          _enviados.add(r.skuId!);
        }
      }
    }
  }

  Future<void> _salvar() async {
    setState(() => _salvando = true);
    final erro = await widget.estado.salvarColeta(widget.visita, widget.modelo, gerais: _gerais, porSku: _porSku);
    if (!mounted) {
      return;
    }
    setState(() => _salvando = false);
    if (erro != null) {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(erro)));

      return;
    }
    Navigator.of(context).pop(true);
  }

  Widget _campo(CampoColeta campo, Map<String, Object?> destino, {required bool travado, required String prefixo}) {
    final valor = destino[campo.chave];
    final rotulo = campo.obrigatorio ? '${campo.rotulo} *' : campo.rotulo;
    final chave = ValueKey('$prefixo${campo.chave}');
    switch (campo.tipo) {
      case 'sim_nao':
        final atual = valor == true || valor == 'sim' ? 'sim' : (valor == false || valor == 'nao' ? 'nao' : '');

        return DropdownButtonFormField<String>(
          key: chave,
          initialValue: atual,
          decoration: InputDecoration(labelText: rotulo),
          items: const [
            DropdownMenuItem(value: '', child: Text('—')),
            DropdownMenuItem(value: 'sim', child: Text('Sim')),
            DropdownMenuItem(value: 'nao', child: Text('Não')),
          ],
          onChanged: travado ? null : (v) => destino[campo.chave] = (v == null || v.isEmpty) ? null : v,
        );
      case 'escolha':
        return DropdownButtonFormField<String>(
          key: chave,
          initialValue: campo.opcoes.contains(valor) ? valor as String : null,
          decoration: InputDecoration(labelText: rotulo),
          items: campo.opcoes.map((o) => DropdownMenuItem(value: o, child: Text(o))).toList(),
          onChanged: travado ? null : (v) => destino[campo.chave] = v,
        );
      case 'data':
        return InkWell(
          key: chave,
          onTap: travado
              ? null
              : () async {
                  final agora = DateTime.now();
                  final escolhida = await showDatePicker(
                    context: context,
                    initialDate: agora,
                    firstDate: DateTime(agora.year - 1),
                    lastDate: DateTime(agora.year + 6),
                  );
                  if (escolhida != null) {
                    setState(() => destino[campo.chave] =
                        '${escolhida.year.toString().padLeft(4, '0')}-${escolhida.month.toString().padLeft(2, '0')}-${escolhida.day.toString().padLeft(2, '0')}');
                  }
                },
          child: InputDecorator(
            decoration: InputDecoration(labelText: rotulo),
            child: Text(valor == null ? 'Toque para escolher a data' : _dataBr(valor.toString())),
          ),
        );
      case 'numero':
      case 'preco':
      case 'quantidade':
        return TextFormField(
          key: chave,
          initialValue: valor?.toString() ?? '',
          enabled: !travado,
          keyboardType: TextInputType.numberWithOptions(decimal: campo.tipo != 'quantidade'),
          decoration: InputDecoration(labelText: rotulo, prefixText: campo.tipo == 'preco' ? 'R\$ ' : null),
          onChanged: (v) => destino[campo.chave] = v,
        );
      default:
        return TextFormField(
          key: chave,
          initialValue: valor?.toString() ?? '',
          enabled: !travado,
          maxLines: campo.tipo == 'texto' ? 2 : 1,
          decoration: InputDecoration(labelText: rotulo),
          onChanged: (v) => destino[campo.chave] = v,
        );
    }
  }

  static String _dataBr(String iso) {
    final p = iso.split('-');

    return p.length == 3 ? '${p[2]}/${p[1]}/${p[0]}' : iso;
  }

  @override
  Widget build(BuildContext context) {
    final modelo = widget.modelo;
    final skus = (widget.industria?.skus ?? const <Sku>[])
        .where((s) => _busca.isEmpty || '${s.codigo} ${s.descricao}'.toLowerCase().contains(_busca.toLowerCase()))
        .toList();

    return Scaffold(
      appBar: AppBar(title: Text(modelo.nome)),
      body: ListView(
        padding: const EdgeInsets.all(12),
        children: [
          if (modelo.camposGerais.isNotEmpty)
            Card(
              child: Padding(
                padding: const EdgeInsets.all(12),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    if (_geralEnviado) const Text('Já enviado: não dá para alterar.'),
                    for (final c in modelo.camposGerais) ...[
                      _campo(c, _gerais, travado: _geralEnviado, prefixo: 'g-'),
                      const SizedBox(height: 8),
                    ],
                  ],
                ),
              ),
            ),
          if (modelo.temPorSku) ...[
            const SizedBox(height: 8),
            Text('Por produto', style: Theme.of(context).textTheme.titleMedium),
            const Text('Responda só os produtos que você conferiu. Os campos com * são obrigatórios para cada produto respondido.'),
            const SizedBox(height: 8),
            TextField(
              decoration: const InputDecoration(prefixIcon: Icon(Icons.search), hintText: 'Buscar produto'),
              onChanged: (v) => setState(() => _busca = v),
            ),
            for (final sku in skus)
              Card(
                key: ValueKey('sku-${sku.id}'),
                child: ExpansionTile(
                  title: Text('${sku.codigo} · ${sku.descricao}'),
                  subtitle: _porSku[sku.id]?.values.any((v) => v != null && v.toString().isNotEmpty) == true
                      ? Text(_enviados.contains(sku.id) ? 'Enviado' : 'Respondido')
                      : null,
                  childrenPadding: const EdgeInsets.fromLTRB(12, 0, 12, 12),
                  children: [
                    for (final c in modelo.camposPorSku) ...[
                      _campo(c, _porSku.putIfAbsent(sku.id, () => {}), travado: _enviados.contains(sku.id), prefixo: 's${sku.id}-'),
                      const SizedBox(height: 8),
                    ],
                  ],
                ),
              ),
          ],
          const SizedBox(height: 16),
          FilledButton.icon(
            onPressed: _salvando ? null : _salvar,
            icon: const Icon(Icons.save_outlined),
            label: const Text('Salvar respostas'),
          ),
        ],
      ),
    );
  }
}
