<?php

namespace App\Services\Operacao;

use App\Enums\Perfil;
use App\Models\Agencia;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Pessoa;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Equipe de uma agência, lida de dentro da operação de uma indústria.
 *
 * A agência vinculada guarda admin, supervisores e promotores no próprio banco; a indústria
 * só conhece o id de cada pessoa. Este serviço abre o banco da agência por uma conexão
 * própria (sem trocar o tenant atual nem mexer em transação aberta) e devolve o mínimo:
 * id, nome, perfil, situação. A leitura é feita uma vez por agência por requisição.
 *
 * Agência sem `agencia_tenant_id` (cadastro interno antigo) continua lendo os usuários locais.
 */
class Equipe
{
    /** @var array<int, Collection<int, Pessoa>> por id da agência local */
    private static array $cache = [];

    /** Descarta a leitura em memória e fecha as conexões abertas com outras instalações. */
    public static function esquecer(): void
    {
        self::$cache = [];
        foreach (array_keys(config('database.connections', [])) as $nome) {
            if (str_starts_with($nome, 'instalacao_')) {
                DB::purge($nome);
            }
        }
    }

    /** Toda a equipe (admin da agência, supervisores e promotores), ativos ou não. */
    public function de(Agencia|int $agencia): Collection
    {
        $agencia = $agencia instanceof Agencia ? $agencia : Agencia::find($agencia);
        if (! $agencia) {
            return collect();
        }

        return self::$cache[(tenant()?->getTenantKey() ?? '').':'.$agencia->id] ??= $this->ler($agencia);
    }

    /**
     * @return Collection<int, Pessoa> promotores da agência por nome. Por padrão só os ativos e
     *                                 habilitados para a indústria em uso; com `$cidade`, quem tem
     *                                 aquela cidade base vem primeiro.
     */
    public function promotores(Agencia|int $agencia, bool $somenteAtivos = true, bool $somenteHabilitados = true, ?string $cidade = null): Collection
    {
        $industria = tenant()?->getTenantKey();
        $cidade = $cidade ? mb_strtolower(trim($cidade)) : null;

        return $this->de($agencia)
            ->filter(fn (Pessoa $p) => $p->perfil === Perfil::Promotor
                && (! $somenteAtivos || $p->ativo)
                && (! $somenteHabilitados || $p->habilitadoPara($industria)))
            ->sortBy(fn (Pessoa $p) => [$cidade && mb_strtolower((string) $p->cidadeBase) === $cidade ? 0 : 1, mb_strtolower($p->nome)])
            ->values();
    }

    /** Quem gere a equipe (admins e supervisores ativos): destinatários dos avisos. */
    public function gestores(Agencia|int $agencia): Collection
    {
        return $this->de($agencia)
            ->filter(fn (Pessoa $p) => $p->ativo && in_array($p->perfil, [Perfil::AdminInstalacao, Perfil::AdminAgencia, Perfil::Supervisor], true))
            ->values();
    }

    public function pessoa(Agencia|int|null $agencia, ?int $id): ?Pessoa
    {
        if ($id === null) {
            return null;
        }
        if ($agencia === null) {
            // Roteiro sem agência (dados anteriores ao vínculo): o id é de um usuário local.
            $usuario = User::find($id);

            return $usuario ? Pessoa::deUsuario($usuario, (int) $usuario->agencia_id) : null;
        }

        return $this->de($agencia)->first(fn (Pessoa $p) => $p->id === $id);
    }

    /**
     * Promotor ativo que pode receber visitas desta indústria. Com `$data`, também não pode
     * estar afastado nesse dia.
     */
    public function promotorAtivo(Agencia|int|null $agencia, ?int $id, ?string $data = null): ?Pessoa
    {
        $p = $this->pessoa($agencia, $id);
        if (! $p || ! $p->ativo || $p->perfil !== Perfil::Promotor) {
            return null;
        }
        if (! $p->habilitadoPara(tenant()?->getTenantKey()) || ($data !== null && $p->afastadoEm($data))) {
            return null;
        }

        return $p;
    }

    public function nome(Agencia|int|null $agencia, ?int $id): ?string
    {
        return $this->pessoa($agencia, $id)?->nome;
    }

    /** @return Collection<int, Pessoa> */
    private function ler(Agencia $agencia): Collection
    {
        $colunas = ['id', 'nome', 'perfil', 'ativo', 'tipo_vinculo', 'agencia_id', 'cidade_base', 'uf_base', 'habilitacao_restrita'];

        if ($agencia->agencia_tenant_id === null) {
            $usuarios = User::whereIn('perfil', [Perfil::AdminAgencia->value, Perfil::Supervisor->value, Perfil::Promotor->value])
                ->where('agencia_id', $agencia->id)->get($colunas);
        } else {
            $origem = Tenant::find($agencia->agencia_tenant_id);
            if (! $origem) {
                return collect();
            }
            // Na instalação da agência todo mundo é da própria agência; o admin da instalação é o
            // admin da agência.
            $conexao = self::conexao($origem);
            $usuarios = User::on($conexao)
                ->whereIn('perfil', [Perfil::AdminInstalacao->value, Perfil::AdminAgencia->value, Perfil::Supervisor->value, Perfil::Promotor->value])
                ->get($colunas);
            $habilitacoes = DB::connection($conexao)->table('promotor_industrias')->get(['usuario_id', 'industria_id'])->groupBy('usuario_id');
            $afastamentos = DB::connection($conexao)->table('afastamentos')->whereDate('fim', '>=', today()->toDateString())->get(['usuario_id', 'inicio', 'fim'])->groupBy('usuario_id');

            return $usuarios->map(fn (User $u) => Pessoa::deUsuario(
                $u,
                $agencia->id,
                $u->habilitacao_restrita ? ($habilitacoes[$u->id] ?? collect())->pluck('industria_id')->all() : null,
                ($afastamentos[$u->id] ?? collect())->map(fn ($a) => [substr((string) $a->inicio, 0, 10), substr((string) $a->fim, 0, 10)])->all(),
            ))->values();
        }

        return $usuarios->map(fn (User $u) => Pessoa::deUsuario($u, $agencia->id))->values();
    }

    /** Conexão própria com o banco de outra instalação, registrada uma vez por processo. */
    public static function conexao(Tenant $tenant): string
    {
        $nome = 'instalacao_'.$tenant->getTenantKey();
        if (! config("database.connections.{$nome}")) {
            config(["database.connections.{$nome}" => $tenant->database()->connection()]);
        }

        return $nome;
    }
}
