<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Chamado;
use App\Models\ChamadoMensagem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Central de atendimento (lado do fornecedor): todos os chamados, com resposta e fechamento. */
class ChamadoController extends Controller
{
    public function index(Request $request): View
    {
        $situacao = $request->query('situacao');

        return view('central.chamados.index', [
            'chamados' => Chamado::with('empresa')->when(array_key_exists((string) $situacao, Chamado::SITUACOES), fn ($q) => $q->where('situacao', $situacao))
                ->orderByRaw("CASE situacao WHEN 'aberto' THEN 0 WHEN 'respondido' THEN 1 ELSE 2 END")->orderByDesc('ultima_mensagem_em')->limit(200)->get(),
            'situacoes' => Chamado::SITUACOES,
            'categorias' => Chamado::CATEGORIAS,
            'filtro' => $situacao,
        ]);
    }

    public function show(int $chamado): View
    {
        return view('central.chamados.show', ['chamado' => Chamado::with(['empresa', 'mensagens'])->findOrFail($chamado), 'categorias' => Chamado::CATEGORIAS, 'situacoes' => Chamado::SITUACOES]);
    }

    public function responder(Request $request, int $chamado): RedirectResponse
    {
        $c = Chamado::findOrFail($chamado);
        $dados = $request->validate(['mensagem' => ['required', 'string', 'max:5000'], 'situacao' => ['required', Rule::in(['respondido', 'fechado'])]]);
        ChamadoMensagem::create(['chamado_id' => $c->id, 'autor' => 'fornecedor', 'autor_nome' => (string) $request->user('admin')->nome, 'texto' => $dados['mensagem'], 'created_at' => now()]);
        $c->update(['situacao' => $dados['situacao'], 'ultima_mensagem_em' => now()]);

        return redirect()->route('central.chamados.show', $c->id)->with('status', 'Resposta enviada.');
    }
}
