<?php

namespace App\Http\Controllers\Api;

use App\Enums\Perfil;
use App\Enums\StatusVisita;
use App\Http\Controllers\Controller;
use App\Models\Demanda;
use App\Models\Sku;
use App\Models\Visita;
use App\Services\Operacao\AlertasOperacao;
use App\Services\Operacao\Geo;
use App\Services\Operacao\MarcaDagua;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ExecucaoVisitaController extends Controller
{
    private function propria(Request $request, int $id): Visita
    {
        $u = $request->user();
        abort_unless($u->perfil === Perfil::Promotor && $u->ativo, 403);

        return Visita::where('agencia_id', $u->agencia_id)->whereHas('roteiro', fn ($q) => $q->where('promotor_id', $u->id))->lockForUpdate()->findOrFail($id);
    }

    public function foto(Request $request, int $visita, MarcaDagua $marca)
    {
        $dados = $request->validate([
            'uuid' => ['required', 'uuid'], 'tipo' => ['required', Rule::in(['antes', 'depois', 'ruptura'])],
            'foto' => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:5120', 'dimensions:max_width=4096,max_height=4096'],
            'lat' => ['required', 'numeric', 'between:-90,90'], 'lng' => ['required', 'numeric', 'between:-180,180'],
            'gps_em' => ['required', 'date_format:Y-m-d\TH:i:sP'], 'aparelho_em' => ['required', 'date_format:Y-m-d\TH:i:sP'],
            'gps_simulado' => ['required', 'boolean', 'declined'],
        ]);
        $gravado = null;
        try {
            return DB::connection('tenant')->transaction(function () use ($request, $visita, $dados, $marca, &$gravado) {
                $v = $this->propria($request, $visita);
                $hash = hash_file('sha256', $request->file('foto')->getRealPath());
                $meta = ['lat' => (float) $dados['lat'], 'lng' => (float) $dados['lng'], 'gps_em' => Carbon::parse($dados['gps_em'])->toIso8601String(), 'aparelho_em' => Carbon::parse($dados['aparelho_em'])->toIso8601String()];
                $existente = $v->fotos()->where('uuid', $dados['uuid'])->first();
                if ($existente) {
                    $anterior = $existente->metadados;
                    abort_unless($existente->hash_origem === $hash && $existente->tipo === $dados['tipo'] && (float) $anterior['lat'] === $meta['lat'] && (float) $anterior['lng'] === $meta['lng'] && Carbon::parse($anterior['gps_em'])->eq(Carbon::parse($meta['gps_em'])) && Carbon::parse($anterior['aparelho_em'])->eq(Carbon::parse($meta['aparelho_em'])), 409, 'UUID já usado com outra foto ou metadados.');

                    return response()->json(['foto' => $existente]);
                }
                abort_unless($v->check_in && $v->status === StatusVisita::EmAndamento, 409, 'É necessário check-in e visita em andamento.');
                $gps = Carbon::parse($dados['gps_em']);
                if ($gps->lt(Carbon::parse($v->check_in['gps_em'])) || $gps->gt(now()->addMinutes(5))) {
                    throw ValidationException::withMessages(['gps_em' => 'A foto deve ser posterior ao check-in e não pode estar no futuro.']);
                }
                $antes = $v->fotos()->where('tipo', 'antes')->get();
                if ($dados['tipo'] === 'depois' && ($antes->isEmpty() || $antes->contains(fn ($f) => $gps->lt(Carbon::parse($f->metadados['gps_em']))))) {
                    throw ValidationException::withMessages(['tipo' => 'Envie a foto antes primeiro; a foto depois deve ter horário posterior.']);
                }
                if ($dados['tipo'] === 'antes' && $v->fotos()->where('tipo', 'depois')->exists()) {
                    throw ValidationException::withMessages(['tipo' => 'As fotos antes devem ser enviadas antes das fotos depois.']);
                }
                $meta['distancia_m'] = Geo::distancia($v->pdv, $meta['lat'], $meta['lng']);
                $meta['recebido_em'] = now()->toIso8601String();
                $meta['relogio_divergente'] = abs($gps->diffInSeconds(Carbon::parse($dados['aparelho_em']))) > 300;
                $gravado = $marca->gravar($request->file('foto'), 'visitas/'.$v->id.'/'.$dados['uuid'].'.jpg', [
                    'PDV '.$v->pdv_id.' - '.$v->pdv->nome, strtoupper($dados['tipo']).' | GPS '.$gps->setTimezone(config('app.timezone'))->toIso8601String(),
                    'LAT '.$meta['lat'].' | LNG '.$meta['lng'], 'Recebido '.$meta['recebido_em'],
                ]);
                $foto = $v->fotos()->create(['uuid' => $dados['uuid'], 'tipo' => $dados['tipo'], ...$gravado, 'hash_origem' => $hash, 'metadados' => $meta]);

                return response()->json(['foto' => $foto], 201);
            });
        } catch (\Throwable $e) {
            if ($gravado) {
                Storage::disk($gravado['disco'])->delete($gravado['caminho']);
            }
            throw $e;
        }
    }

    public function concluir(Request $request, int $visita, AlertasOperacao $alertas)
    {
        $dados = $request->validate([
            'uuid' => ['required', 'uuid'], 'tipo' => ['required', Rule::in(['normal', 'ruptura', 'impedimento'])],
            'finalizada_em' => ['required', 'date_format:Y-m-d\TH:i:sP'],
            'observacao' => ['nullable', 'required_if:tipo,impedimento', 'string', 'max:2000'],
            'itens' => ['nullable', 'required_unless:tipo,impedimento', 'array', 'min:'.($request->input('tipo') === 'impedimento' ? '0' : '1'), 'max:500'],
            'itens.*.sku_id' => ['required', 'integer', 'distinct'],
            'itens.*.estoque' => ['nullable', Rule::in(['tem_estoque', 'somente_gondola'])],
        ]);

        return DB::connection('tenant')->transaction(function () use ($request, $visita, $dados, $alertas) {
            $v = $this->propria($request, $visita);
            $itens = collect($dados['itens'] ?? [])->map(fn ($i) => ['sku_id' => (int) $i['sku_id'], 'estoque' => $dados['tipo'] === 'ruptura' ? 'ruptura' : ($i['estoque'] ?? null)])->sortBy('sku_id')->values();
            if ($dados['tipo'] === 'impedimento' && $itens->isNotEmpty()) {
                throw ValidationException::withMessages(['itens' => 'Impedimento não registra produtos trabalhados.']);
            }
            if ($dados['tipo'] === 'normal' && $itens->contains(fn ($i) => $i['estoque'] === null)) {
                throw ValidationException::withMessages(['itens' => 'Informe a situação de estoque de cada SKU.']);
            }
            $fim = Carbon::parse($dados['finalizada_em'])->setTimezone(config('app.timezone'));
            $payload = ['tipo' => $dados['tipo'], 'finalizada_em' => $fim->toIso8601String(), 'observacao' => trim($dados['observacao'] ?? ''), 'itens' => $itens->all()];
            $hash = hash('sha256', json_encode($payload));
            if ($v->finalizacao_uuid) {
                abort_unless($v->finalizacao_uuid === $dados['uuid'] && $v->finalizacao_hash === $hash, 409, 'A visita já possui outra finalização.');

                return response()->json(['visita' => $v->load('itens')]);
            }
            abort_unless($v->check_in && $v->status === StatusVisita::EmAndamento, 409, 'A visita precisa estar em andamento.');
            if (Sku::where('industria_id', $v->industria_id)->where('ativo', true)->whereIn('id', $itens->pluck('sku_id'))->count() !== $itens->count()) {
                throw ValidationException::withMessages(['itens' => 'Há SKU inativo ou de outra indústria.']);
            }
            $fotos = $v->fotos()->get();
            if ($dados['tipo'] === 'normal' && (! $fotos->contains('tipo', 'antes') || ! $fotos->contains('tipo', 'depois'))) {
                throw ValidationException::withMessages(['fotos' => 'São obrigatórias fotos antes e depois.']);
            }
            if ($dados['tipo'] === 'ruptura' && ! $fotos->contains('tipo', 'ruptura')) {
                throw ValidationException::withMessages(['fotos' => 'Envie uma foto da ruptura.']);
            }
            $inicio = Carbon::parse($v->check_in['gps_em']);
            if ($fim->lt($inicio) || $fim->gt(now()->addMinutes(5)) || $fotos->contains(fn ($f) => $fim->lt(Carbon::parse($f->metadados['gps_em'])))) {
                throw ValidationException::withMessages(['finalizada_em' => 'Horário incompatível com o check-in ou as fotos.']);
            }
            $v->forceFill(['tipo_conclusao' => $dados['tipo'], 'finalizacao_uuid' => $dados['uuid'], 'finalizacao_hash' => $hash, 'concluida_em' => $fim, 'recebida_em' => now(), 'observacao' => $payload['observacao'], 'duracao_segundos' => (int) $inicio->diffInSeconds($fim), 'status' => $dados['tipo'] === 'impedimento' ? StatusVisita::EmAtencao : StatusVisita::Realizada])->save();
            $v->itens()->createMany($itens->map(fn ($i) => ['sku_id' => $i['sku_id'], 'situacao_estoque' => $i['estoque']])->all());
            if ($dados['tipo'] !== 'normal') {
                $alertas->emitir($dados['tipo'], $v, $dados['tipo'] === 'ruptura' ? 'Ruptura de estoque' : 'Impedimento de acesso', $v->pdv->nome.' · '.$v->observacao);
            }
            if ($dados['tipo'] !== 'impedimento' && $demanda = Demanda::where('visita_id', $v->id)->where('status', 'aprovada')->first()) {
                $demanda->update(['status' => 'atendida', 'atendida_em' => $fim]);
                $alertas->emitir('demanda_atendida', $demanda, 'Demanda atendida', $v->pdv->nome);
            }

            return response()->json(['visita' => $v->load('itens')], 201);
        });
    }
}
