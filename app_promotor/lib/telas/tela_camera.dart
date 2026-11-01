import 'dart:typed_data';

import 'package:camera/camera.dart';
import 'package:flutter/material.dart';

/// Câmera do próprio app: a foto nunca vem da galeria. Devolve os bytes JPEG
/// capturados (a marca d'água é aplicada depois, com a posição já lida).
class TelaCamera extends StatefulWidget {
  const TelaCamera({super.key, required this.titulo});

  final String titulo;

  @override
  State<TelaCamera> createState() => _TelaCameraState();
}

class _TelaCameraState extends State<TelaCamera> {
  CameraController? _controle;
  String? _erro;
  bool _capturando = false;

  @override
  void initState() {
    super.initState();
    _iniciar();
  }

  Future<void> _iniciar() async {
    try {
      final cameras = await availableCameras();
      if (cameras.isEmpty) {
        setState(() => _erro = 'Nenhuma câmera encontrada no aparelho.');

        return;
      }
      final traseira = cameras.firstWhere(
        (c) => c.lensDirection == CameraLensDirection.back,
        orElse: () => cameras.first,
      );
      final controle = CameraController(traseira, ResolutionPreset.high, enableAudio: false);
      await controle.initialize();
      if (!mounted) {
        await controle.dispose();

        return;
      }
      setState(() => _controle = controle);
    } on CameraException catch (erro) {
      setState(() => _erro = erro.code == 'CameraAccessDenied'
          ? 'Permita o uso da câmera nas configurações do app.'
          : 'Não foi possível abrir a câmera: ${erro.description ?? erro.code}');
    }
  }

  Future<void> _capturar() async {
    final controle = _controle;
    if (controle == null || _capturando) {
      return;
    }
    setState(() => _capturando = true);
    try {
      final arquivo = await controle.takePicture();
      final bytes = await arquivo.readAsBytes();
      if (mounted) {
        Navigator.of(context).pop<Uint8List>(bytes);
      }
    } on CameraException catch (erro) {
      if (mounted) {
        setState(() {
          _capturando = false;
          _erro = 'Falha ao fotografar: ${erro.description ?? erro.code}';
        });
      }
    }
  }

  @override
  void dispose() {
    _controle?.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final controle = _controle;

    return Scaffold(
      backgroundColor: Colors.black,
      appBar: AppBar(title: Text(widget.titulo), backgroundColor: Colors.black, foregroundColor: Colors.white),
      body: _erro != null
          ? Center(
              child: Padding(
                padding: const EdgeInsets.all(24),
                child: Text(_erro!, style: const TextStyle(color: Colors.white), textAlign: TextAlign.center),
              ),
            )
          : controle == null
              ? const Center(child: CircularProgressIndicator())
              : Center(child: CameraPreview(controle)),
      floatingActionButtonLocation: FloatingActionButtonLocation.centerFloat,
      floatingActionButton: controle == null || _erro != null
          ? null
          : FloatingActionButton.large(
              onPressed: _capturando ? null : _capturar,
              tooltip: 'Fotografar',
              child: _capturando ? const CircularProgressIndicator() : const Icon(Icons.camera_alt),
            ),
    );
  }
}
