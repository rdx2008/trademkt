<?php

namespace App\Http\Middleware;

use App\Enums\TipoInstalacao;
use App\Services\Rede\Operacao;
use Closure;
use Illuminate\Http\Request;

/**
 * Na instalação da agência, as telas da operação rodam dentro do banco da indústria escolhida
 * (ver {@see Operacao}). Ficam na própria agência: equipe, aparência, entrada e saída.
 *
 * Na API do app, a indústria vem na rota (`{industria}` = código da empresa); no painel, da
 * sessão (`operacao`), com a primeira indústria vinculada como padrão.
 */
class AbrirOperacaoDaAgencia
{
    /** Rotas que ficam no banco da agência. */
    private const LOCAIS = ['login', 'logout', 'usuarios.*', 'configuracoes.*', 'marca.*', 'operacao.*', 'api.login', 'api.logout', 'api.me', 'api.roteiro'];

    /** O que a agência pode abrir dentro da indústria. O resto é cadastro da indústria. */
    private const PERMITIDAS = ['dashboard', 'roteiros.*', 'visitas.mover', 'visitas.show', 'visitas.fotos', 'demandas.*', 'alertas.*', 'indicadores.*', 'bi.*', 'contratos.index', 'contratos.edit', 'contratos.update', 'pdvs.index', 'skus.index', 'skus.foto', 'regioes.index', 'redes.index', 'api.*'];

    public function __construct(private Operacao $operacao) {}

    public function handle(Request $request, Closure $next)
    {
        $agencia = tenant();
        $eu = $request->user();
        if (! $eu || $agencia?->tipo !== TipoInstalacao::Agencia || $request->routeIs(...self::LOCAIS)) {
            return $next($request);
        }

        $codigo = $request->route('industria');
        if ($codigo === null && $request->routeIs('api.*')) {
            // Na API da agência, a execução sempre diz a indústria na rota.
            return $next($request);
        }
        if ($codigo !== null) {
            // O código só serve para escolher o banco; os controllers não o recebem.
            $request->route()->forgetParameter('industria');
        }
        $industria = $codigo !== null
            ? $this->operacao->porCodigo($agencia, (string) $codigo)
            : $this->operacao->escolher($agencia, $request->hasSession() ? $request->session()->get('operacao') : null);

        if (! $industria) {
            // Sem vínculo: a agência ainda não atende nenhuma indústria.
            abort_if($codigo !== null, 404, 'Indústria não encontrada.');

            return $next($request);
        }

        abort_unless($request->routeIs(...self::PERMITIDAS), 403, 'Este cadastro é administrado pela indústria.');

        abort_unless($this->operacao->abrir($agencia, $industria, $eu), 403, 'O acesso da agência a esta indústria não está mais autorizado.');
        if ($request->hasSession()) {
            $request->session()->put('operacao', $industria->id);
        }

        try {
            return $next($request);
        } finally {
            // A sessão e o restante da resposta são gravados na instalação da agência.
            $this->operacao->fechar($eu);
        }
    }
}
