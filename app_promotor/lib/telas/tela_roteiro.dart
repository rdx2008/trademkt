import 'package:flutter/material.dart';

import '../estado_app.dart';
import '../modelos/execucao.dart';
import '../modelos/roteiro.dart';
import '../util/datas.dart';
import 'menu_lateral.dart';
import 'tela_visita.dart';

class TelaRoteiro extends StatefulWidget {
  const TelaRoteiro({super.key, required this.estado});

  final EstadoApp estado;

  @override
  State<TelaRoteiro> createState() => _TelaRoteiroState();
}

class _TelaRoteiroState extends State<TelaRoteiro> with WidgetsBindingObserver {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    widget.estado.addListener(_mostrarAviso);
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    widget.estado.removeListener(_mostrarAviso);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    // Ao voltar para o app, tenta enviar o que ficou na fila.
    if (state == AppLifecycleState.resumed && widget.estado.pendentes > 0) {
      widget.estado.sincronizar(silencioso: true);
    }
  }

  void _mostrarAviso() {
    final aviso = widget.estado.aviso;
    if (aviso == null || !mounted) {
      return;
    }
    widget.estado.limparAviso();
    ScaffoldMessenger.of(context)
      ..hideCurrentSnackBar()
      ..showSnackBar(SnackBar(content: Text(aviso)));
  }

  @override
  Widget build(BuildContext context) {
    final estado = widget.estado;

    return ListenableBuilder(
      listenable: estado,
      builder: (context, _) {
        final roteiro = estado.roteiro;
        final data = DateTime.parse(estado.dataRoteiro);

        return Scaffold(
          drawer: MenuLateral(estado: estado),
          appBar: AppBar(
            title: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(estado.sessao?.empresa.nomeExibido ?? 'Roteiro', style: const TextStyle(fontSize: 18)),
                if (estado.sessao != null)
                  Text(estado.sessao!.usuario.nome, style: const TextStyle(fontSize: 12, fontWeight: FontWeight.normal)),
              ],
            ),
            actions: [
              IconButton(
                tooltip: 'Sincronizar',
                onPressed: estado.sincronizando ? null : () => estado.sincronizar(),
                icon: Badge(
                  isLabelVisible: estado.pendentes > 0 || estado.comProblema > 0,
                  backgroundColor: estado.comProblema > 0 ? Theme.of(context).colorScheme.error : null,
                  label: Text('${estado.pendentes + estado.comProblema}'),
                  child: estado.sincronizando
                      ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2))
                      : const Icon(Icons.cloud_upload_outlined),
                ),
              ),
            ],
            bottom: PreferredSize(
              preferredSize: const Size.fromHeight(48),
              child: Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  IconButton(onPressed: () => estado.mudarData(-1), icon: const Icon(Icons.chevron_left)),
                  Text(_tituloData(data), style: Theme.of(context).textTheme.titleMedium),
                  IconButton(onPressed: () => estado.mudarData(1), icon: const Icon(Icons.chevron_right)),
                ],
              ),
            ),
          ),
          body: RefreshIndicator(
            onRefresh: estado.atualizarRoteiro,
            child: roteiro == null
                ? ListView(
                    children: [
                      const SizedBox(height: 80),
                      Icon(Icons.cloud_off, size: 64, color: Theme.of(context).colorScheme.outline),
                      const SizedBox(height: 16),
                      Text(
                        estado.atualizandoRoteiro
                            ? 'Baixando o roteiro...'
                            : 'Nenhum roteiro salvo para este dia.\nPuxe para baixo para atualizar.',
                        textAlign: TextAlign.center,
                      ),
                    ],
                  )
                : roteiro.visitas.isEmpty
                    ? ListView(
                        children: const [
                          SizedBox(height: 80),
                          Icon(Icons.event_available, size: 64),
                          SizedBox(height: 16),
                          Text('Sem visitas neste dia.', textAlign: TextAlign.center),
                        ],
                      )
                    : ListView.builder(
                        padding: const EdgeInsets.only(bottom: 80),
                        itemCount: roteiro.visitas.length,
                        itemBuilder: (context, i) => _CartaoVisita(
                          estado: estado,
                          visita: roteiro.visitas[i],
                          industria: estado.industriaDa(roteiro.visitas[i]),
                        ),
                      ),
          ),
          bottomNavigationBar: _RodapeSincronizacao(estado: estado),
        );
      },
    );
  }

  String _tituloData(DateTime data) {
    final hoje = somenteData(DateTime.now());
    final dia = somenteData(data);
    const semana = ['segunda', 'terça', 'quarta', 'quinta', 'sexta', 'sábado', 'domingo'];
    final nome = semana[dia.weekday - 1];
    if (dia == hoje) {
      return 'Hoje, ${formatarDataBr(dia)}';
    }
    if (dia == hoje.subtract(const Duration(days: 1))) {
      return 'Ontem, ${formatarDataBr(dia)}';
    }
    if (dia == hoje.add(const Duration(days: 1))) {
      return 'Amanhã, ${formatarDataBr(dia)}';
    }

    return '$nome, ${formatarDataBr(dia)}';
  }
}

class _CartaoVisita extends StatelessWidget {
  const _CartaoVisita({required this.estado, required this.visita, required this.industria});

  final EstadoApp estado;
  final Visita visita;
  final Industria? industria;

  @override
  Widget build(BuildContext context) {
    final execucao = estado.execucao(visita.id);
    final status = estado.statusExibido(visita);
    final cor = corStatus(context, status);

    return Card(
      margin: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
      child: ListTile(
        leading: CircleAvatar(backgroundColor: cor, foregroundColor: Colors.white, child: Text('${visita.ordem}')),
        title: Text(visita.pdv.nome, style: const TextStyle(fontWeight: FontWeight.w600)),
        subtitle: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            if (visita.pdv.endereco != null) Text(visita.pdv.endereco!, maxLines: 2, overflow: TextOverflow.ellipsis),
            Text(industria?.nome ?? 'Indústria ${visita.industriaId}'),
            const SizedBox(height: 4),
            Wrap(
              spacing: 6,
              children: [
                Chip(
                  label: Text(StatusVisita.rotulo(status)),
                  visualDensity: VisualDensity.compact,
                  backgroundColor: cor.withValues(alpha: 0.15),
                  side: BorderSide(color: cor),
                ),
                if (visita.origem == 'demanda')
                  const Chip(label: Text('Sob demanda'), visualDensity: VisualDensity.compact),
                if (execucao.temPendencias)
                  const Chip(
                    avatar: Icon(Icons.cloud_upload_outlined, size: 16),
                    label: Text('A enviar'),
                    visualDensity: VisualDensity.compact,
                  ),
              ],
            ),
          ],
        ),
        isThreeLine: true,
        trailing: const Icon(Icons.chevron_right),
        onTap: () => Navigator.of(context).push(
          MaterialPageRoute<void>(builder: (_) => TelaVisita(estado: estado, visitaId: visita.id)),
        ),
      ),
    );
  }
}

Color corStatus(BuildContext context, String status) => switch (status) {
      StatusVisita.realizada => Colors.green.shade700,
      StatusVisita.emAndamento => Colors.blue.shade700,
      StatusVisita.emAtencao => Colors.orange.shade800,
      StatusVisita.naoExecutada => Theme.of(context).colorScheme.error,
      _ => Colors.grey.shade600,
    };

class _RodapeSincronizacao extends StatelessWidget {
  const _RodapeSincronizacao({required this.estado});

  final EstadoApp estado;

  @override
  Widget build(BuildContext context) {
    final texto = estado.sincronizando
        ? 'Enviando...'
        : estado.comProblema > 0
            ? '${estado.comProblema} envio(s) com problema. Abra a visita para ver.'
            : estado.pendentes > 0
                ? '${estado.pendentes} item(ns) aguardando envio.'
                : 'Tudo sincronizado.';
    final baixado = estado.roteiroBaixadoEm;

    return Material(
      color: Theme.of(context).colorScheme.surfaceContainerHigh,
      child: SafeArea(
        top: false,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
          child: Row(
            children: [
              Icon(
                estado.comProblema > 0
                    ? Icons.error_outline
                    : estado.pendentes > 0
                        ? Icons.cloud_upload_outlined
                        : Icons.cloud_done_outlined,
                size: 18,
                color: estado.comProblema > 0 ? Theme.of(context).colorScheme.error : null,
              ),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  baixado == null ? texto : '$texto Roteiro baixado em ${formatarDataHoraBr(baixado)}.',
                  style: Theme.of(context).textTheme.bodySmall,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Resumo curto de uma execução, usado em mais de uma tela.
String resumoExecucao(ExecucaoVisita e) {
  if (e.concluida) {
    return e.conclusao!.tipo.rotulo;
  }
  if (e.temCheckIn) {
    return 'Em andamento';
  }

  return 'Prevista';
}
