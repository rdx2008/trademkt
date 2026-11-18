<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\Perfil;
use App\Http\Controllers\Controller;
use App\Models\FotoAvaliacao;
use App\Services\Painel\ResumoOperacional;
use App\Support\Contexto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class VisitaController extends Controller
{
    private function visivel(Request $r, int $id)
    {
        return app(ResumoOperacional::class)->visitas($r->user())->with(['fotos.avaliacoes', 'itens.sku', 'pdv', 'industria', 'agencia', 'roteiro', 'demanda'])->findOrFail($id);
    }

    public function show(Request $r, int $visita)
    {
        return view('tenant.visitas.show', ['visita' => $this->visivel($r, $visita)]);
    }

    public function apiShow(Request $r, int $visita)
    {
        abort_unless($r->user()->perfil === Perfil::Promotor, 403);
        $v = $this->visivel($r, $visita);

        return response()->json(['visita' => [
            'id' => $v->id, 'status' => $v->status->value, 'tipo_conclusao' => $v->tipo_conclusao, 'check_in' => $v->check_in,
            'concluida_em' => $v->concluida_em?->toIso8601String(), 'recebida_em' => $v->recebida_em?->toIso8601String(),
            'duracao_segundos' => $v->duracao_segundos, 'observacao' => $v->observacao, 'demanda_id' => $v->demanda?->id,
            'itens' => $v->itens->map(fn ($i) => ['sku_id' => $i->sku_id, 'codigo' => $i->sku->codigo, 'descricao' => $i->sku->descricao, 'situacao_estoque' => $i->situacao_estoque]),
            'fotos' => $v->fotos->map(fn ($f) => ['id' => $f->id, 'uuid' => $f->uuid, 'tipo' => $f->tipo, 'metadados' => $f->metadados, 'url' => Contexto::rotaApi('visitas.fotos', ['visita' => $v, 'foto' => $f])]),
        ]]);
    }

    /** Nota (1 a 5) e tags da foto, por quem acompanha a operação. Refazer a avaliação substitui a anterior. */
    public function avaliar(Request $r, int $visita, int $foto)
    {
        $eu = $r->user();
        abort_unless($eu->temPerfil(Perfil::AdminInstalacao, Perfil::AdminAgencia, Perfil::Supervisor, Perfil::GerenteTrade, Perfil::Representante), 403);
        $v = $this->visivel($r, $visita);
        $f = $v->fotos->firstWhere('id', $foto);
        abort_unless($f, 404);
        $dados = $r->validate([
            'nota' => ['nullable', 'integer', 'between:1,5'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', Rule::in(array_keys(FotoAvaliacao::TAGS))],
        ]);
        $nota = $dados['nota'] ?? null;
        $tags = array_values(array_unique($dados['tags'] ?? []));
        $chave = ['foto_id' => $f->id, 'agencia_id' => Contexto::agenciaLocal()?->id ?? $eu->agencia_id, 'usuario_id' => $eu->id];
        if ($nota === null && $tags === []) {
            FotoAvaliacao::where($chave)->delete();
        } else {
            FotoAvaliacao::updateOrCreate($chave, ['usuario_nome' => $eu->nome, 'nota' => $nota, 'tags' => $tags ?: null]);
        }

        return back()->with('status', 'Avaliação da foto salva.');
    }

    public function foto(Request $r, int $visita, int $foto)
    {
        $v = $this->visivel($r, $visita);
        $f = $v->fotos->firstWhere('id', $foto);
        abort_unless($f && Storage::disk($f->disco)->exists($f->caminho), 404);

        return Storage::disk($f->disco)->response($f->caminho, headers: ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
