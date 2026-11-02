<?php

namespace App\Support;

use App\Enums\Perfil;
use App\Enums\TipoInstalacao;
use App\Models\Alerta;
use App\Models\User;
use App\Services\Bi\PainelBi;
use Illuminate\Support\Facades\Route;

/**
 * Menu lateral das instalações, em tópicos com submenu.
 *
 * Cada item respeita as mesmas permissões do menu antigo: quem não pode abrir a tela não
 * a vê. Tópicos que ficam sem nenhum item somem; tópico com um único item vira link direto.
 *
 * @phpstan-type Item array{rotulo: string, url: string, ativo: bool}
 * @phpstan-type Topico array{rotulo: string, icone: string, url: ?string, ativo: bool, badge: ?int, itens: list<Item>}
 */
class MenuLateral
{
    /** @return list<Topico> */
    public static function montar(User $eu): array
    {
        $industria = tenant()->tipo === TipoInstalacao::Industria;
        $gestao = $eu->temPerfil(Perfil::AdminInstalacao, Perfil::AdminAgencia);
        $acompanha = $eu->temPerfil(Perfil::AdminInstalacao, Perfil::AdminAgencia, Perfil::Supervisor, Perfil::GerenteTrade, Perfil::Representante);
        $catalogoEditavel = $eu->temPerfil(Perfil::AdminInstalacao, Perfil::GerenteTrade);

        $inicio = match ($eu->perfil) {
            Perfil::Promotor => 'Minha agenda',
            Perfil::GerentePdv => 'Minhas lojas',
            Perfil::Representante => 'Minha carteira',
            default => 'Visão geral',
        };

        $topicos = [
            self::link($inicio, 'flaticon-381-home-2', 'dashboard', ['dashboard']),

            self::topico('Operação', 'flaticon-381-route', [
                self::item('Roteiros', 'roteiros.semana', ['roteiros.*'], $acompanha),
                self::item('Contratos de visita', 'contratos.index', ['contratos.*'], $acompanha),
                self::item('Demandas e rupturas', 'demandas.index', ['demandas.*'], true),
            ]),

            self::topico('Resultados', 'flaticon-381-controls-3', [
                self::item($eu->perfil === Perfil::Promotor ? 'Minha produção' : 'Indicadores e fotos', 'indicadores.index', ['indicadores.*'], true),
                self::item(PainelBi::visao($eu) === 'industria' ? 'BI da indústria' : 'BI da agência', 'bi.index', ['bi.*'], $eu->podeVerBi()),
            ]),

            self::topico('Cadastros', 'flaticon-381-folder-1', [
                self::item('PDVs', 'pdvs.index', ['pdvs.*'], $acompanha || $eu->perfil === Perfil::GerentePdv),
                self::item('Catálogo de SKUs', 'skus.index', ['skus.*'], $acompanha, exceto: ['skus.importar*']),
                self::item('Importar produtos', 'skus.importar', ['skus.importar*'], $catalogoEditavel),
                self::item($industria ? 'Agências internas' : 'Agências', 'agencias.index', ['agencias.*'], $acompanha),
                self::item('Indústrias', 'industrias.index', ['industrias.*'], $eu->temPerfil(Perfil::AdminInstalacao)),
                self::item('Regiões', 'regioes.index', ['regioes.*'], $gestao && ! $eu->agenciaDaRede()),
                self::item('Redes de supermercados', 'redes.index', ['redes.*'], $gestao && ! $eu->agenciaDaRede()),
            ]),

            self::topico('Parcerias', 'flaticon-381-networking', [
                self::item(
                    $industria ? 'Buscar agências' : 'Parcerias com indústrias',
                    'parcerias.index',
                    ['parcerias.*'],
                    ($industria && $eu->temPerfil(Perfil::AdminInstalacao, Perfil::GerenteTrade))
                        || (! $industria && $gestao),
                ),
            ]),

            self::topico('Equipe', 'flaticon-381-user-9', [
                self::item('Usuários', 'usuarios.index', ['usuarios.*'], $eu->temPerfil(Perfil::AdminInstalacao, Perfil::AdminAgencia, Perfil::GerenteTrade)),
            ]),

            self::topico('Configurações', 'flaticon-381-settings-2', [
                self::item('Aparência', 'configuracoes.aparencia', ['configuracoes.*'], $eu->temPerfil(Perfil::AdminInstalacao) && tenant()->whiteLabelAtivo()),
            ]),

            self::link(
                'Meus alertas',
                'flaticon-381-notification',
                'alertas.index',
                ['alertas.*'],
                Alerta::visiveisPara($eu)->whereNull('lida_em')->count() ?: null,
            ),
        ];

        return array_values(array_filter($topicos, fn ($t) => $t['url'] !== null || $t['itens'] !== []));
    }

    /** @return array<string, mixed> */
    private static function link(string $rotulo, string $icone, string $rota, array $padroes, ?int $badge = null): array
    {
        return [
            'rotulo' => $rotulo,
            'icone' => $icone,
            'url' => route($rota),
            'ativo' => request()->routeIs(...$padroes),
            'badge' => $badge,
            'itens' => [],
        ];
    }

    /**
     * @param  list<array<string, mixed>|null>  $itens
     * @return array<string, mixed>
     */
    private static function topico(string $rotulo, string $icone, array $itens): array
    {
        $itens = array_values(array_filter($itens));

        // Tópico com um único item vira link direto, sem submenu.
        if (count($itens) === 1) {
            return [
                'rotulo' => $itens[0]['rotulo'],
                'icone' => $icone,
                'url' => $itens[0]['url'],
                'ativo' => $itens[0]['ativo'],
                'badge' => null,
                'itens' => [],
            ];
        }

        return [
            'rotulo' => $rotulo,
            'icone' => $icone,
            'url' => null,
            'ativo' => collect($itens)->contains('ativo', true),
            'badge' => null,
            'itens' => $itens,
        ];
    }

    /**
     * @param  list<string>  $padroes
     * @param  list<string>  $exceto
     * @return array<string, mixed>|null null quando o perfil não pode ver o item
     */
    private static function item(string $rotulo, string $rota, array $padroes, bool $visivel, array $exceto = []): ?array
    {
        if (! $visivel || ! Route::has($rota)) {
            return null;
        }

        return [
            'rotulo' => $rotulo,
            'url' => route($rota),
            'ativo' => request()->routeIs(...$padroes) && ! ($exceto !== [] && request()->routeIs(...$exceto)),
        ];
    }
}
