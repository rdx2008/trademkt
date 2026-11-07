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

        return self::$cache[$agencia->id] ??= $this->ler($agencia);
    }

    /** @return Collection<int, Pessoa> promotores da agência (por padrão só os ativos), por nome */
    public function promotores(Agencia|int $agencia, bool $somenteAtivos = true): Collection
    {
        return $this->de($agencia)
            ->filter(fn (Pessoa $p) => $p->perfil === Perfil::Promotor && (! $somenteAtivos || $p->ativo))
            ->sortBy('nome')->values();
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

    public function promotorAtivo(Agencia|int|null $agencia, ?int $id): ?Pessoa
    {
        $p = $this->pessoa($agencia, $id);

        return $p && $p->ativo && $p->perfil === Perfil::Promotor ? $p : null;
    }

    public function nome(Agencia|int|null $agencia, ?int $id): ?string
    {
        return $this->pessoa($agencia, $id)?->nome;
    }

    /** @return Collection<int, Pessoa> */
    private function ler(Agencia $agencia): Collection
    {
        $colunas = ['id', 'nome', 'perfil', 'ativo', 'tipo_vinculo', 'agencia_id'];

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
            $usuarios = User::on(self::conexao($origem))
                ->whereIn('perfil', [Perfil::AdminInstalacao->value, Perfil::AdminAgencia->value, Perfil::Supervisor->value, Perfil::Promotor->value])
                ->get($colunas);
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
