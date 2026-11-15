<?php

namespace App\Http\Controllers;

use App\Services\AplicativoAndroid;
use App\Services\ConteudoInstitucional;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Página pública de download do app do promotor (Android) e a tela do super admin que publica o APK.
 */
class AplicativoController extends Controller
{
    public function __construct(private AplicativoAndroid $aplicativo) {}

    public function pagina(ConteudoInstitucional $conteudo): View
    {
        return view('institucional.aplicativo', ['site' => $conteudo->publico(), 'app' => $this->aplicativo->atual()]);
    }

    public function baixar(): BinaryFileResponse
    {
        abort_unless($this->aplicativo->atual(), 404);

        return response()->download($this->aplicativo->caminho(), 'promotor.apk', [
            'Content-Type' => 'application/vnd.android.package-archive',
            'Cache-Control' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    // ---------------------------------------------------------------- super admin

    public function edit(): View
    {
        return view('central.aplicativo', ['app' => $this->aplicativo->atual()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'versao' => ['required', 'string', 'max:30', 'regex:/^[0-9A-Za-z.\-+ ]+$/'],
            'arquivo' => ['required', 'file', 'extensions:apk', 'max:61440'],
        ], ['arquivo.extensions' => 'Envie o arquivo .apk do aplicativo.', 'arquivo.max' => 'O APK pode ter até 60 MB. Gere o APK de release.']);
        $arquivo = $request->file('arquivo');
        if (! AplicativoAndroid::pareceApk($arquivo->getRealPath())) {
            return back()->withErrors(['arquivo' => 'O arquivo não é um APK Android válido.'])->withInput();
        }
        $info = $this->aplicativo->publicar($arquivo, trim($request->string('versao')));

        return redirect()->route('central.aplicativo.edit')->with('status', 'Versão '.$info['versao'].' publicada. O link de download já entrega este arquivo.');
    }

    public function destroy(): RedirectResponse
    {
        $this->aplicativo->remover();

        return redirect()->route('central.aplicativo.edit')->with('status', 'APK removido. A página de download mostra "em breve".');
    }
}
