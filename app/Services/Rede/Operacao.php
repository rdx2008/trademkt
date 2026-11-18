<?php

namespace App\Services\Rede;

use App\Enums\Perfil;
use App\Enums\TipoInstalacao;
use App\Models\Agencia;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vinculo;
use App\Support\Contexto;
use Illuminate\Support\Collection;

/**
 * Abre a operação de uma indústria para um usuário da agência vinculada.
 *
 * O usuário entrou na instalação da agência (onde mora o cadastro dele). A operação — PDVs,
 * catálogo, contratos, roteiros, visitas — mora no banco da indústria. Abrir a operação troca o
 * banco em uso para o da indústria e apresenta o usuário como gente da agência local: o
 * `agencia_id` passa a ser a linha da agência naquele banco e o admin da instalação da agência
 * age como admin da agência. Nada é gravado no usuário; é só o que vale nesta requisição.
 */
class Operacao
{
    /** @return Collection<int, Tenant> indústrias que a agência atende (vínculo e instalações ativos) */
    public function industriasDe(Tenant $agencia): Collection
    {
        return Vinculo::de($agencia)->with('industria')->get()->map(fn (Vinculo $v) => $v->industria)->sortBy('nome')->values();
    }

    /** Indústria a abrir: a escolhida (se ainda vinculada) ou a primeira. */
    public function escolher(Tenant $agencia, ?string $industriaId): ?Tenant
    {
        $industrias = $this->industriasDe($agencia);

        return $industrias->firstWhere('id', $industriaId) ?? $industrias->first();
    }

    /** Indústria pelo código do app (`codigo` do tenant), se vinculada à agência. */
    public function porCodigo(Tenant $agencia, string $codigo): ?Tenant
    {
        return $this->industriasDe($agencia)->firstWhere('codigo', strtolower(trim($codigo)));
    }

    /**
     * Troca para o banco da indústria e apresenta o usuário como gente da agência local.
     * Devolve a agência local, ou null se a agência não existe/está inativa lá.
     */
    public function abrir(Tenant $agencia, Tenant $industria, ?User $eu = null): ?Agencia
    {
        if ($agencia->tipo !== TipoInstalacao::Agencia || $industria->tipo !== TipoInstalacao::Industria) {
            return null;
        }
        tenancy()->initialize($industria);
        $local = Agencia::where('agencia_tenant_id', $agencia->id)->where('ativo', true)->first();
        if (! $local) {
            tenancy()->initialize($agencia);

            return null;
        }
        Contexto::abrirOperacao($agencia, $industria, $local);
        if ($eu) {
            $this->apresentar($eu, $local);
        }

        return $local;
    }

    /** Volta para o banco da agência e desfaz a apresentação do usuário. */
    public function fechar(?User $eu = null): void
    {
        $agencia = Contexto::instalacao();
        if ($eu) {
            $this->restaurar($eu);
        }
        Contexto::limpar();
        if ($agencia && Contexto::operacao() === null && tenant()?->id !== $agencia->id) {
            tenancy()->initialize($agencia);
        }
    }

    /** @var array<int, array{agencia_id: ?int, industria_id: ?int, perfil: Perfil}> por objeto de usuário */
    private array $origens = [];

    private function apresentar(User $eu, Agencia $local): void
    {
        $this->origens[spl_object_id($eu)] ??= ['agencia_id' => $eu->agencia_id, 'industria_id' => $eu->industria_id, 'perfil' => $eu->perfil];
        $eu->agencia_id = $local->id;
        $eu->industria_id = null;
        $eu->setRelation('agencia', $local);
        if ($eu->perfil === Perfil::AdminInstalacao) {
            $eu->perfil = Perfil::AdminAgencia;
        }
        // Nada disso vai para o banco: o cadastro mora na agência.
        $eu->syncOriginal();
    }

    private function restaurar(User $eu): void
    {
        $origem = $this->origens[spl_object_id($eu)] ?? null;
        if (! $origem) {
            return;
        }
        $eu->agencia_id = $origem['agencia_id'];
        $eu->industria_id = $origem['industria_id'];
        $eu->perfil = $origem['perfil'];
        $eu->unsetRelation('agencia');
        $eu->syncOriginal();
        unset($this->origens[spl_object_id($eu)]);
    }
}
