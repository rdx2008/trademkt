import 'package:flutter/material.dart';

import '../api/cliente_api.dart';
import '../estado_app.dart';
import '../modelos/producao.dart';
import '../util/datas.dart';

/// "Minha produção": SKUs trabalhados no mês, por dia. Para o freelancer conferir o que tem a receber.
class TelaProducao extends StatefulWidget {
  const TelaProducao({super.key, required this.estado, this.inicio});

  final EstadoApp estado;
  final DateTime? inicio;

  @override
  State<TelaProducao> createState() => _TelaProducaoState();
}

class _TelaProducaoState extends State<TelaProducao> {
  late DateTime _mes;
  Producao? _producao;
  String? _erro;
  bool _carregando = false;

  @override
  void initState() {
    super.initState();
    final base = widget.inicio ?? DateTime.now();
    _mes = DateTime(base.year, base.month);
    _carregar();
  }

  String get _chave => '${_mes.year}-${_mes.month.toString().padLeft(2, '0')}';

  Future<void> _carregar() async {
    final pedido = _chave;
    setState(() {
      _carregando = true;
      _erro = null;
    });
    try {
      final producao = await widget.estado.carregarProducao(pedido);
      if (mounted && pedido == _chave) {
        setState(() => _producao = producao);
      }
    } on ErroApi catch (erro) {
      if (mounted) {
        setState(() => _erro = erro.descricao);
      }
    } on SemConexao {
      if (mounted) {
        setState(() => _erro = 'Sem internet. A produção do mês só aparece com conexão.');
      }
    } finally {
      if (mounted) {
        setState(() => _carregando = false);
      }
    }
  }

  void _mudarMes(int meses) {
    setState(() {
      _mes = DateTime(_mes.year, _mes.month + meses);
      _producao = null;
    });
    _carregar();
  }

  static const _nomes = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

  @override
  Widget build(BuildContext context) {
    final producao = _producao;
    final tema = Theme.of(context);

    return Scaffold(
      appBar: AppBar(title: const Text('Minha produção')),
      body: Column(
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              IconButton(key: const Key('mes-anterior'), tooltip: 'Mês anterior', onPressed: () => _mudarMes(-1), icon: const Icon(Icons.chevron_left)),
              Text('${_nomes[_mes.month - 1]} de ${_mes.year}', style: tema.textTheme.titleMedium),
              IconButton(key: const Key('mes-seguinte'), tooltip: 'Próximo mês', onPressed: () => _mudarMes(1), icon: const Icon(Icons.chevron_right)),
            ],
          ),
          if (_carregando) const LinearProgressIndicator(),
          if (_erro != null)
            Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                children: [
                  Text(_erro!, textAlign: TextAlign.center, style: TextStyle(color: tema.colorScheme.error)),
                  TextButton(onPressed: _carregar, child: const Text('Tentar de novo')),
                ],
              ),
            ),
          if (producao != null) ...[
            Card(
              margin: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.spaceAround,
                  children: [
                    _Total(rotulo: 'SKUs trabalhados', valor: '${producao.total}', chave: const Key('total-skus')),
                    _Total(rotulo: 'Visitas', valor: '${producao.visitas}'),
                    _Total(rotulo: 'Dias', valor: '${producao.dias.length}'),
                  ],
                ),
              ),
            ),
            Expanded(
              child: producao.dias.isEmpty
                  ? const Center(child: Text('Nenhum SKU trabalhado neste mês.'))
                  : ListView(
                      padding: const EdgeInsets.only(bottom: 24),
                      children: [
                        for (final dia in producao.dias)
                          ExpansionTile(
                            key: Key('dia-${dia.data}'),
                            title: Text(formatarDataBr(DateTime.parse(dia.data))),
                            subtitle: Text('${dia.total} SKU(s) trabalhado(s)'),
                            children: [
                              for (final item in dia.itens)
                                ListTile(
                                  dense: true,
                                  title: Text(item.descricao),
                                  subtitle: Text('${item.pdv} · ${item.situacaoRotulo}'),
                                  trailing: Text(item.codigo, style: tema.textTheme.bodySmall),
                                ),
                            ],
                          ),
                      ],
                    ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 0, 16, 12),
              child: Text('Ruptura não conta como produção. O pagamento não passa pelo sistema.', style: tema.textTheme.bodySmall),
            ),
          ] else if (_erro == null && !_carregando)
            const Expanded(child: SizedBox()),
        ],
      ),
    );
  }
}

class _Total extends StatelessWidget {
  const _Total({required this.rotulo, required this.valor, this.chave});

  final String rotulo;
  final String valor;
  final Key? chave;

  @override
  Widget build(BuildContext context) => Column(
        children: [
          Text(valor, key: chave, style: Theme.of(context).textTheme.headlineSmall),
          Text(rotulo, style: Theme.of(context).textTheme.bodySmall),
        ],
      );
}
