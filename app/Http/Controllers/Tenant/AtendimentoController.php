<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Chamado;
use App\Models\ChamadoMensagem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Central de atendimento (lado da empresa): chamados ao fornecedor do sistema e a conversa deles.
 * Cada empresa vê só os próprios chamados; o fornecedor responde pelo painel central. Admin da
 * instalação, admin da agência e gerente de trade abrem chamados.
 */
class AtendimentoController extends Controller
{
    private function empresa(): string
    {
        return (string) tenant('id');
    }

    private function doMeuTenant(int $id): Chamado
    {
        return Chamado::where('tenant_id', $this->empresa())->findOrFail($id);
    }

    public function index(): View
    {
        return view('tenant.atendimento.index', [
            'chamados' => Chamado::where('tenant_id', $this->empresa())->orderByDesc('ultima_mensagem_em')->get(),
            'categorias' => Chamado::CATEGORIAS,
            'situacoes' => Chamado::SITUACOES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'assunto' => ['required', 'string', 'max:150'],
            'categoria' => ['required', Rule::in(array_keys(Chamado::CATEGORIAS))],
            'mensagem' => ['required', 'string', 'max:5000'],
        ]);
        $eu = $request->user();

        $chamado = DB::connection(config('tenancy.database.central_connection'))->transaction(function () use ($dados, $eu) {
            $c = Chamado::create([
                'tenant_id' => $this->empresa(), 'assunto' => $dados['assunto'], 'categoria' => $dados['categoria'],
                'aberto_por_nome' => $eu->nome, 'aberto_por_email' => $eu->email, 'ultima_mensagem_em' => now(),
            ]);
            ChamadoMensagem::create(['chamado_id' => $c->id, 'autor' => 'empresa', 'autor_nome' => $eu->nome, 'texto' => $dados['mensagem'], 'created_at' => now()]);

            return $c;
        });

        return redirect()->route('atendimento.show', $chamado->id)->with('status', 'Chamado aberto. O fornecedor responde por aqui.');
    }

    public function show(int $chamado): View
    {
        $c = $this->doMeuTenant($chamado);

        return view('tenant.atendimento.show', ['chamado' => $c->load('mensagens'), 'categorias' => Chamado::CATEGORIAS, 'situacoes' => Chamado::SITUACOES]);
    }

    public function responder(Request $request, int $chamado): RedirectResponse
    {
        $c = $this->doMeuTenant($chamado);
        abort_if($c->situacao === 'fechado', 409, 'Este chamado está fechado. Abra um novo.');
        $dados = $request->validate(['mensagem' => ['required', 'string', 'max:5000']]);
        ChamadoMensagem::create(['chamado_id' => $c->id, 'autor' => 'empresa', 'autor_nome' => $request->user()->nome, 'texto' => $dados['mensagem'], 'created_at' => now()]);
        $c->update(['situacao' => 'aberto', 'ultima_mensagem_em' => now()]);

        return redirect()->route('atendimento.show', $c->id)->with('status', 'Mensagem enviada.');
    }

    public function fechar(int $chamado): RedirectResponse
    {
        $this->doMeuTenant($chamado)->update(['situacao' => 'fechado']);

        return redirect()->route('atendimento.index')->with('status', 'Chamado fechado.');
    }
}
