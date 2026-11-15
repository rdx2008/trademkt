<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\SiteContato;
use App\Services\ConteudoInstitucional;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SiteInstitucionalController extends Controller
{
    public function edit(ConteudoInstitucional $conteudo)
    {
        $registro = $conteudo->registro();

        return view('central.site.edit', ['site' => $registro->rascunho, 'registro' => $registro]);
    }

    public function previa(ConteudoInstitucional $conteudo)
    {
        return response()->view('institucional.index', ['site' => $conteudo->registro()->rascunho, 'previa' => true])
            ->header('X-Robots-Tag', 'noindex, nofollow')->header('Cache-Control', 'no-store, private');
    }

    public function update(Request $request, ConteudoInstitucional $conteudo)
    {
        $padrao = ConteudoInstitucional::padrao();
        $regras = [
            'conteudo' => ['required', 'array:'.implode(',', array_diff(array_keys($padrao), ['imagens']))],
            'conteudo.marca' => ['required', 'string', 'max:60'],
            'conteudo.cor' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'conteudo.botao' => ['required', 'string', 'max:60'],
            'conteudo.botao_url' => ['required', 'string', 'max:500', 'regex:~^(#[a-z][a-z0-9-]*|https://[^\s<>]+|mailto:[^\s<>]+|tel:[+0-9(). -]+)$~i'],
            'conteudo.botao_secundario' => ['required', 'string', 'max:60'],
            'conteudo.email' => ['nullable', 'email', 'max:190'],
            'conteudo.telefone' => ['nullable', 'string', 'max:40'],
            'conteudo.seo_titulo' => ['required', 'string', 'max:90'],
            'conteudo.seo_descricao' => ['required', 'string', 'max:200'],
            'conteudo.rodape' => ['required', 'string', 'max:400'],
            'conteudo.dados_legais' => ['nullable', 'string', 'max:1000'],
            'conteudo.privacidade' => ['required', 'string', 'max:30000'],
            'conteudo.secoes' => ['required', 'array:'.implode(',', array_keys($padrao['secoes']))],
            'acao' => ['required', 'in:salvar,publicar'],
            'uploads' => ['sometimes', 'array:logo,favicon,hero,offline,indicadores,compartilhamento'],
            'remover_imagens' => ['sometimes', 'array:logo,favicon,hero,offline,indicadores,compartilhamento'],
            'remover_imagens.*' => ['boolean'],
            'uploads.*' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:4096', 'dimensions:max_width=5000,max_height=5000'],
        ];
        foreach (['whatsapp', 'instagram', 'linkedin'] as $campo) {
            $regras['conteudo.'.$campo] = ['nullable', 'url:https', 'max:500'];
        }
        foreach ($padrao['secoes'] as $chave => $secao) {
            $prefixo = 'conteudo.secoes.'.$chave;
            $regras[$prefixo] = ['required', 'array:titulo,texto,ordem,visivel,itens'];
            $regras[$prefixo.'.titulo'] = ['required', 'string', 'max:180'];
            $regras[$prefixo.'.texto'] = ['nullable', 'string', 'max:1200'];
            $regras[$prefixo.'.ordem'] = ['required', 'integer', 'between:0,999'];
            $regras[$prefixo.'.visivel'] = ['required', 'boolean'];
            $regras[$prefixo.'.itens'] = ['sometimes', 'array', 'max:12'];
            $regras[$prefixo.'.itens.*'] = ['array:titulo,texto,icone,visivel'];
            $regras[$prefixo.'.itens.*.titulo'] = ['required', 'string', 'max:180'];
            $regras[$prefixo.'.itens.*.texto'] = ['required', 'string', 'max:1500'];
            $regras[$prefixo.'.itens.*.icone'] = ['required', Rule::in(['industria', 'equipe', 'loja', 'roteiro', 'celular', 'camera', 'grafico', 'pin', 'alvo', 'alerta', 'caixa', 'offline'])];
            $regras[$prefixo.'.itens.*.visivel'] = ['required', 'boolean'];
        }
        $dados = $request->validate($regras);
        $novo = $dados['conteudo'];
        foreach ($novo['secoes'] as &$secao) {
            $secao['visivel'] = (bool) $secao['visivel'];
            $secao['itens'] = array_values($secao['itens'] ?? []);
            foreach ($secao['itens'] as &$item) {
                $item['visivel'] = (bool) $item['visivel'];
            }
            unset($item);
        }
        unset($secao);
        // Evita botões brancos sobre cores claras, mantendo contraste AA.
        $rgb = array_map(fn ($p) => hexdec($p) / 255, str_split(substr($novo['cor'], 1), 2));
        $linear = array_map(fn ($v) => $v <= 0.04045 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, $rgb);
        $lum = 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
        if (1.05 / ($lum + 0.05) < 4.5) {
            throw ValidationException::withMessages(['conteudo.cor' => 'Escolha uma cor mais escura para garantir a leitura dos botões (contraste mínimo 4,5:1).']);
        }
        $registro = $conteudo->registro();
        $novo['imagens'] = $registro->rascunho['imagens'] ?? [];
        foreach (['logo', 'favicon', 'hero', 'offline', 'indicadores', 'compartilhamento'] as $tipo) {
            if ($request->boolean('remover_imagens.'.$tipo)) {
                unset($novo['imagens'][$tipo]);
            }
            if ($request->hasFile('uploads.'.$tipo)) {
                $foto = $request->file('uploads.'.$tipo);
                $nome = Str::uuid().'.'.$foto->extension();
                $foto->storeAs('institucional', $nome, 'local');
                $novo['imagens'][$tipo] = $nome;
            }
        }
        $registro->rascunho = $novo;
        if ($dados['acao'] === 'publicar') {
            $registro->publicado = $novo;
            $registro->publicado_em = now();
        }
        $registro->save();

        return redirect()->route('central.site.edit')->with('status', $dados['acao'] === 'publicar' ? 'Site publicado.' : 'Rascunho salvo. A versão pública não foi alterada.');
    }

    public function contatos()
    {
        return view('central.site.contatos', ['contatos' => SiteContato::latest()->paginate(25)]);
    }

    public function situacao(Request $request, SiteContato $contato)
    {
        $contato->update($request->validate(['situacao' => ['required', 'in:novo,em_atendimento,concluido']]));

        return back()->with('status', 'Situação do contato atualizada.');
    }

    public function excluirContato(SiteContato $contato)
    {
        $contato->delete();

        return back()->with('status', 'Contato excluído.');
    }
}
