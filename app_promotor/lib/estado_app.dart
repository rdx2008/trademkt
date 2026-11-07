/// Estado central do app: sessão, roteiro em cache, execuções locais e fila.
/// As telas observam este objeto; toda gravação passa por aqui.
library;

import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:http/http.dart' as http;
import 'package:uuid/uuid.dart';

import 'api/api_promotor.dart';
import 'api/cliente_api.dart';
import 'dados/repositorio_local.dart';
import 'dominio/regras_visita.dart';
import 'modelos/execucao.dart';
import 'modelos/payloads.dart';
import 'modelos/roteiro.dart';
import 'modelos/usuario.dart';
import 'servicos/fotos.dart';
import 'servicos/localizacao.dart';
import 'servicos/marca.dart';
import 'servicos/sessao.dart';
import 'sincronizacao/item_fila.dart';
import 'sincronizacao/sincronizador.dart';
import 'util/datas.dart';
import 'util/marca_dagua.dart';

class EstadoApp extends ChangeNotifier {
  EstadoApp({
    required http.Client httpClient,
    required this.armazenamento,
    required this.repositorio,
    required this.localizacao,
    required this.fotos,
    required this.urlCentral,
    this.dispositivo = 'Android',
    this.fabricaRepositorio,
    ServicoMarca? servicoMarca,
  })  : _http = httpClient,
        _servicoMarca = servicoMarca ?? ServicoMarca();

  final http.Client _http;
  final ArmazenamentoSessao armazenamento;

  /// Banco da conta ativa. Com [fabricaRepositorio], cada conta tem o seu.
  RepositorioLocal repositorio;

  /// Abre o banco local de uma conta (pela chave). Sem ela, todas as contas dividem [repositorio].
  final Future<RepositorioLocal> Function(String chave)? fabricaRepositorio;
  final ServicoMarca _servicoMarca;
  final ServicoLocalizacao localizacao;
  final ServicoFotos fotos;

  /// Domínio central para descobrir a empresa pelo código.
  final String urlCentral;
  final String dispositivo;

  Sessao? sessao;

  /// Todas as contas do aparelho (a ativa é [sessao]).
  List<Sessao> contas = [];

  /// O promotor está acrescentando outra empresa, com uma conta já aberta.
  bool adicionandoConta = false;

  /// Empresa escolhida na primeira tela, antes do login.
  Empresa? empresaPendente;

  /// O token deixou de valer (401): pede login de novo sem apagar o que está no aparelho.
  bool sessaoExpirada = false;

  bool carregado = false;
  bool sincronizando = false;
  bool atualizandoRoteiro = false;
  String? aviso;

  String dataRoteiro = formatarDataIso(DateTime.now());
  Roteiro? roteiro;
  DateTime? roteiroBaixadoEm;
  Map<String, ExecucaoVisita> execucoes = {};
  int pendentes = 0;
  int comProblema = 0;

  ApiPromotor? _api;
  Sincronizador? _sincronizador;
  bool _rodadaPendente = false;

  bool get autenticado => sessao != null && !sessaoExpirada;

  /// Marca a vestir agora: da empresa em escolha (antes do login ou ao acrescentar conta)
  /// ou da conta ativa.
  Marca? get marcaAtual {
    if (empresaPendente != null) {
      return empresaPendente!.marca;
    }

    return adicionandoConta ? null : sessao?.empresa.marca;
  }

  // ----------------------------------------------------------------- início

  Future<void> carregar() async {
    sessao = await armazenamento.carregar();
    contas = await armazenamento.listar();
    if (sessao != null) {
      await _abrirRepositorio(sessao!);
      _configurarApi();
    }
    await _carregarLocal();
    carregado = true;
    notifyListeners();
    if (sessao != null) {
      unawaited(atualizarMarca());
      unawaited(atualizarRoteiro(silencioso: true));
      if (pendentes > 0) {
        unawaited(sincronizar(silencioso: true));
      }
    }
  }

  Future<void> _abrirRepositorio(Sessao conta) async {
    final fabrica = fabricaRepositorio;
    if (fabrica != null) {
      repositorio = await fabrica(conta.chave);
    }
  }

  void _configurarApi() {
    final s = sessao!;
    _api = ApiPromotor(ClienteApi(http: _http, baseUrl: s.empresa.api, token: s.token));
    _sincronizador = Sincronizador(api: _api!, repositorio: repositorio, lerArquivo: fotos.ler);
  }

  Future<void> _carregarLocal() async {
    roteiro = await repositorio.obterRoteiro(dataRoteiro);
    roteiroBaixadoEm = await repositorio.baixadoEm(dataRoteiro);
    await _recarregarExecucoes();
  }

  Future<void> _recarregarExecucoes() async {
    execucoes = await repositorio.listarExecucoes();
    pendentes = await repositorio.contarPendentes();
    comProblema = await repositorio.contarComProblema();
  }

  // ------------------------------------------------------- empresa e login

  Future<void> descobrirEmpresa(String codigo) async {
    final empresa = await ApiCentral(http: _http, urlCentral: urlCentral).descobrir(codigo);
    empresaPendente = await _comLogoLocal(empresa);
    notifyListeners();
  }

  /// Baixa o logo da marca (se houver) para uso offline e devolve a empresa com o caminho local.
  Future<Empresa> _comLogoLocal(Empresa empresa, {Empresa? anterior}) async {
    final marca = empresa.marca;
    final url = marca?.logoUrl;
    if (marca == null || url == null) {
      return empresa;
    }
    final antigo = anterior?.marca;
    if (antigo != null && antigo.logoUrl == url && await _servicoMarca.existe(antigo.logoLocal)) {
      return empresa.copiarCom(marca: marca.copiarCom(logoLocal: antigo.logoLocal));
    }
    final caminho = await _servicoMarca.baixarLogo(_http, codigo: empresa.codigo, url: url);

    return caminho == null ? empresa : empresa.copiarCom(marca: marca.copiarCom(logoLocal: caminho));
  }

  /// Relê a marca da empresa ativa na API central (nome, cor e logo podem ter mudado).
  /// Silencioso: sem internet, ou com a empresa informada por URL direta, nada acontece.
  Future<void> atualizarMarca() async {
    final atual = sessao;
    if (atual == null) {
      return;
    }
    try {
      final descoberta = await ApiCentral(http: _http, urlCentral: urlCentral).descobrir(atual.empresa.codigo);
      if (descoberta.api != atual.empresa.api) {
        return;
      }
      final empresa = await _comLogoLocal(descoberta, anterior: atual.empresa);
      final antiga = atual.empresa.marca;
      final igual = empresa.marca == null
          ? antiga == null
          : empresa.marca!.mesmaAparencia(antiga) && empresa.marca!.logoLocal == antiga?.logoLocal;
      if (igual || sessao?.chave != atual.chave) {
        return;
      }
      final nova = atual.copiarCom(empresa: empresa.marca == null ? atual.empresa : empresa);
      sessao = nova;
      await armazenamento.salvar(nova);
      contas = await armazenamento.listar();
      notifyListeners();
    } on Object {
      // Marca é enfeite: erro de rede ou da API não atrapalha o trabalho.
    }
  }

  /// Uso avançado (homologação/local): informar a URL da instalação diretamente.
  void usarUrlDireta(String url) {
    final limpa = url.trim().replaceAll(RegExp(r'/+$'), '');
    empresaPendente = Empresa(codigo: Uri.parse(limpa).host, nome: limpa, url: limpa, api: '$limpa/api/v1');
    notifyListeners();
  }

  void trocarEmpresa() {
    empresaPendente = null;
    notifyListeners();
  }

  /// Abre o fluxo para acrescentar outra empresa sem sair da conta atual.
  void iniciarNovaConta() {
    adicionandoConta = true;
    empresaPendente = null;
    notifyListeners();
  }

  void cancelarNovaConta() {
    adicionandoConta = false;
    empresaPendente = null;
    notifyListeners();
  }

  Future<void> entrar({required String email, required String senha}) async {
    final empresa = empresaPendente ?? sessao?.empresa;
    if (empresa == null) {
      throw ErroApi(status: 422, mensagem: 'Escolha a empresa antes de entrar.');
    }
    final api = ApiPromotor(ClienteApi(http: _http, baseUrl: empresa.api));
    final resultado = await api.login(email: email, senha: senha, dispositivo: dispositivo);
    final nova = Sessao(empresa: empresa, token: resultado.token, usuario: resultado.usuario);

    final anterior = sessao;
    if (fabricaRepositorio == null && anterior != null) {
      // Banco único: trocar de usuário ou empresa exige que nada fique pendente.
      if (anterior.usuario.id != resultado.usuario.id && pendentes > 0) {
        throw ErroApi(
          status: 409,
          mensagem: 'Há envios pendentes de ${anterior.usuario.nome}. Sincronize antes de entrar com outro usuário.',
        );
      }
      if (anterior.chave != nova.chave) {
        await repositorio.limparTudo();
      }
    }

    await _abrirRepositorio(nova);
    sessao = nova;
    sessaoExpirada = false;
    empresaPendente = null;
    adicionandoConta = false;
    await armazenamento.salvar(nova);
    contas = await armazenamento.listar();
    _configurarApi();
    await _carregarLocal();
    notifyListeners();
    unawaited(atualizarRoteiro(silencioso: true));
    if (pendentes > 0) {
      unawaited(sincronizar(silencioso: true));
    }
  }

  /// Muda para outra conta do aparelho. Cada uma mantém roteiro, fila e fotos próprios.
  /// Devolve a mensagem de erro, ou `null` se trocou.
  Future<String?> trocarConta(Sessao destino) async {
    if (destino.chave == sessao?.chave) {
      return null;
    }
    if (sincronizando) {
      return 'Aguarde o envio terminar para trocar de conta.';
    }
    await _ativar(destino);

    return null;
  }

  Future<void> _ativar(Sessao destino) async {
    await _abrirRepositorio(destino);
    await armazenamento.ativar(destino.chave);
    sessao = destino;
    sessaoExpirada = false;
    adicionandoConta = false;
    empresaPendente = null;
    aviso = null;
    roteiro = null;
    execucoes = {};
    pendentes = 0;
    comProblema = 0;
    _configurarApi();
    await _carregarLocal();
    notifyListeners();
    unawaited(atualizarMarca());
    unawaited(atualizarRoteiro(silencioso: true));
    if (pendentes > 0) {
      unawaited(sincronizar(silencioso: true));
    }
  }

  /// Sai da conta ativa (as outras continuam no aparelho e uma delas passa a ser a ativa).
  /// Devolve `false` se há pendências e [forcar] é falso.
  Future<bool> sair({bool forcar = false}) async {
    if (pendentes > 0 && !forcar) {
      return false;
    }
    final atual = sessao;
    try {
      await _api?.logout();
    } on Object {
      // Token será descartado localmente mesmo sem confirmação do servidor.
    }
    if (atual != null) {
      await armazenamento.remover(atual.chave);
    }
    await repositorio.limparTudo();
    contas = await armazenamento.listar();
    sessao = null;
    _api = null;
    _sincronizador = null;
    sessaoExpirada = false;
    adicionandoConta = false;
    roteiro = null;
    execucoes = {};
    pendentes = 0;
    comProblema = 0;
    aviso = null;
    if (contas.isNotEmpty) {
      await _ativar(contas.first);
    } else {
      notifyListeners();
    }

    return true;
  }

  // ---------------------------------------------------------------- roteiro

  Future<void> mudarData(int dias) async {
    final atual = DateTime.parse(dataRoteiro);
    dataRoteiro = formatarDataIso(atual.add(Duration(days: dias)));
    await _carregarLocal();
    notifyListeners();
    await atualizarRoteiro(silencioso: true);
  }

  Future<void> atualizarRoteiro({bool silencioso = false}) async {
    final api = _api;
    if (api == null) {
      return;
    }
    atualizandoRoteiro = true;
    notifyListeners();
    try {
      final novo = await api.roteiro(dataRoteiro);
      await repositorio.salvarRoteiro(novo);
      roteiro = novo;
      roteiroBaixadoEm = DateTime.now();
      await _alinharComServidor(novo);
      if (!silencioso) {
        aviso = 'Roteiro atualizado.';
      }
    } on SemConexao {
      roteiro ??= await repositorio.obterRoteiro(dataRoteiro);
      aviso = roteiro == null ? 'Sem conexão e sem roteiro salvo para este dia.' : 'Sem conexão: mostrando o roteiro salvo.';
    } on ErroApi catch (erro) {
      if (erro.naoAutenticado) {
        sessaoExpirada = true;
      }
      aviso = erro.descricao;
    } finally {
      atualizandoRoteiro = false;
      notifyListeners();
    }
  }

  /// O roteiro traz status e check-in de cada visita: alinha o que está no aparelho.
  Future<void> _alinharComServidor(Roteiro novo) async {
    for (final visita in novo.visitas) {
      if (!execucoes.containsKey(visita.chave)) {
        continue;
      }
      await repositorio.modificarExecucao(visita.chave, (atual) {
        var alterada = atual;
        if (visita.checkIn != null && atual.checkIn != null && !atual.checkIn!.enviado) {
          alterada = alterada.copiarCom(checkIn: atual.checkIn!.copiarCom(enviado: true));
        }
        if (alterada.statusServidor != visita.status) {
          alterada = alterada.copiarCom(statusServidor: visita.status);
        }

        return alterada;
      });
    }
    await _recarregarExecucoes();
  }

  ExecucaoVisita execucao(String visitaId) => execucoes[visitaId] ?? ExecucaoVisita(visitaId: visitaId);

  Industria? industriaDa(Visita visita) => roteiro?.industria(visita);

  /// Status a exibir: o confirmado pelo servidor, senão o do roteiro, ajustado pelo que há no aparelho.
  String statusExibido(Visita visita) {
    final e = execucao(visita.chave);
    if (e.concluida) {
      return e.conclusao!.tipo == TipoConclusao.impedimento ? StatusVisita.emAtencao : StatusVisita.realizada;
    }
    if (e.temCheckIn) {
      return StatusVisita.emAndamento;
    }

    return e.statusServidor ?? visita.status;
  }

  // ------------------------------------------------------------- execução

  Future<void> _gravar(String visitaId, ExecucaoVisita Function(ExecucaoVisita atual) alterar, ItemFila item) async {
    await repositorio.modificarExecucao(visitaId, alterar);
    await repositorio.enfileirar(item);
    await _recarregarExecucoes();
    notifyListeners();
    unawaited(sincronizar(silencioso: true));
  }

  /// Lê o GPS e confere se a foto pode ser tirada. Devolve a posição ou a mensagem de erro.
  Future<({Posicao? posicao, String? erro})> prepararFoto(Visita visita, TipoFoto tipo) async {
    Posicao posicao;
    try {
      posicao = await localizacao.obterPosicao();
    } on ErroLocalizacao catch (erro) {
      return (posicao: null, erro: erro.mensagem);
    }
    final erro = RegrasVisita.validarFoto(visita, execucao(visita.chave), tipo, posicao);

    return (posicao: erro == null ? posicao : null, erro: erro);
  }

  /// Grava a foto capturada (bytes JPEG da câmera) com marca d'água e a põe na fila. A foto de
  /// antes da gôndola (ou da entrada, no impedimento) é o check-in: hora e posição dela abrem a visita.
  Future<String?> registrarFoto(Visita visita, TipoFoto tipo, Uint8List bytes, Posicao posicao) async {
    final e = execucao(visita.chave);
    final erro = RegrasVisita.validarFoto(visita, e, tipo, posicao);
    if (erro != null) {
      return erro;
    }
    final uuid = const Uuid().v4();
    final caminho = await fotos.gravar(
      visitaId: visita.chave,
      uuid: uuid,
      original: bytes,
      linhasMarca: linhasMarcaDagua(
        pdvNome: visita.pdv.nome,
        pdvId: visita.pdv.id,
        tipo: tipo.valor,
        lat: posicao.lat,
        lng: posicao.lng,
        gpsEm: posicao.gpsEm,
      ),
    );
    final foto = FotoLocal(
      uuid: uuid,
      tipo: tipo,
      caminho: caminho,
      lat: posicao.lat,
      lng: posicao.lng,
      gpsEm: posicao.gpsEm,
      aparelhoEm: DateTime.now(),
    );
    final abreVisita = !e.temCheckIn && (tipo == TipoFoto.antes || tipo == TipoFoto.impedimento);
    final checkIn = abreVisita
        ? CheckInLocal(
            lat: posicao.lat,
            lng: posicao.lng,
            gpsEm: posicao.gpsEm,
            aparelhoEm: foto.aparelhoEm,
            distanciaM: RegrasVisita.distanciaAoPdv(visita.pdv, posicao),
          )
        : null;
    await _gravar(
      visita.chave,
      (atual) => atual.copiarCom(fotos: [...atual.fotos, foto], checkIn: atual.checkIn ?? checkIn),
      ItemFila(
        visitaId: visita.chave,
        tipo: TipoItemFila.foto,
        chave: uuid,
        payload: FotoPayload.deLocal(foto).toJson(),
        arquivo: caminho,
        prioridade: prioridadeFila(TipoItemFila.foto, tipoFoto: tipo),
        criadoEm: DateTime.now(),
      ),
    );

    return null;
  }

  Future<void> salvarItens(String visitaId, List<ItemTrabalhado> itens) async {
    await repositorio.modificarExecucao(visitaId, (atual) => atual.copiarCom(itens: itens));
    await _recarregarExecucoes();
    notifyListeners();
  }

  /// Conclui a visita (normal, ruptura ou impedimento). Devolve erro ou `null`.
  Future<String?> concluir(
    Visita visita,
    TipoConclusao tipo, {
    List<ItemTrabalhado>? itens,
    String observacao = '',
  }) async {
    final e = execucao(visita.chave);
    final lista = switch (tipo) {
      TipoConclusao.impedimento => const <ItemTrabalhado>[],
      TipoConclusao.ruptura => (itens ?? const []).map((i) => ItemTrabalhado(skuId: i.skuId)).toList(),
      TipoConclusao.normal => itens ?? e.itens,
    };
    final finalizadaEm = DateTime.now();
    final erro = RegrasVisita.validarConclusao(
      e,
      tipo,
      itens: lista,
      observacao: observacao,
      finalizadaEm: finalizadaEm,
    );
    if (erro != null) {
      return erro;
    }
    final conclusao = ConclusaoLocal(
      uuid: const Uuid().v4(),
      tipo: tipo,
      finalizadaEm: finalizadaEm,
      observacao: observacao.trim(),
      itens: lista,
    );
    await _gravar(
      visita.chave,
      (atual) => atual.copiarCom(conclusao: conclusao, itens: lista),
      ItemFila(
        visitaId: visita.chave,
        tipo: TipoItemFila.conclusao,
        chave: conclusao.uuid,
        payload: ConclusaoPayload.deLocal(conclusao).toJson(),
        prioridade: prioridadeFila(TipoItemFila.conclusao),
        criadoEm: DateTime.now(),
      ),
    );

    return null;
  }

  // ---------------------------------------------------------- sincronização

  Future<ResultadoSincronizacao?> sincronizar({bool silencioso = false}) async {
    final sincronizador = _sincronizador;
    if (sincronizador == null) {
      return null;
    }
    if (sincronizando) {
      // Algo novo entrou na fila durante o envio: roda outra rodada ao terminar.
      _rodadaPendente = true;

      return null;
    }
    sincronizando = true;
    notifyListeners();
    ResultadoSincronizacao resultado;
    try {
      do {
        _rodadaPendente = false;
        resultado = await sincronizador.sincronizar();
        await _recarregarExecucoes();
      } while (_rodadaPendente && !resultado.semConexao && !resultado.sessaoExpirada);
    } finally {
      // Só libera depois de recarregar: quem espera `sincronizando` já vê o estado novo.
      sincronizando = false;
    }
    if (resultado.sessaoExpirada) {
      sessaoExpirada = true;
    }
    if (!silencioso || !resultado.tudoCerto) {
      aviso = resultado.resumo;
    }
    notifyListeners();

    return resultado;
  }

  Future<void> tentarNovamente(String visitaId) async {
    await repositorio.reabrirItens(visitaId);
    await sincronizar();
  }

  Future<List<ItemFila>> itensDaFila(String visitaId) => repositorio.listarFila(visitaId: visitaId);

  /// Consulta o servidor e alinha a execução local. Devolve erro ou `null`.
  Future<String?> reconciliar(String visitaId) async {
    final sincronizador = _sincronizador;
    if (sincronizador == null) {
      return 'Sem sessão.';
    }
    try {
      await sincronizador.reconciliar(visitaId);
    } on SemConexao catch (erro) {
      return erro.mensagem;
    } on ErroApi catch (erro) {
      if (erro.naoAutenticado) {
        sessaoExpirada = true;
      }

      return erro.descricao;
    } finally {
      await _recarregarExecucoes();
      notifyListeners();
    }

    return null;
  }

  void limparAviso() {
    aviso = null;
  }
}
