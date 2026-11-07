<?php

namespace App\Support;

use App\Enums\Perfil;
use App\Enums\TipoVinculo;
use App\Models\User;

/**
 * Pessoa de uma agência vista de dentro da operação de uma indústria: o essencial para
 * atribuir visitas, mostrar nomes e avisar, sem copiar o cadastro. `agenciaId` é a linha da
 * agência no banco da indústria; `id` é o id do usuário no banco de origem.
 */
final class Pessoa
{
    public function __construct(
        public readonly int $id,
        public readonly int $agenciaId,
        public readonly string $nome,
        public readonly Perfil $perfil,
        public readonly bool $ativo,
        public readonly ?TipoVinculo $tipoVinculo = null,
        public readonly ?string $cidadeBase = null,
        public readonly ?string $ufBase = null,
        /** @var list<string>|null ids das indústrias habilitadas; null = todas */
        public readonly ?array $industrias = null,
        /** @var list<array{0: string, 1: string}> períodos de afastamento [início, fim] (Y-m-d) */
        public readonly array $afastamentos = [],
    ) {}

    /**
     * @param  list<string>|null  $industrias
     * @param  list<array{0: string, 1: string}>  $afastamentos
     */
    public static function deUsuario(User $usuario, int $agenciaId, ?array $industrias = null, array $afastamentos = []): self
    {
        return new self(
            $usuario->id, $agenciaId, $usuario->nome, $usuario->perfil, $usuario->ativo, $usuario->tipo_vinculo,
            $usuario->cidade_base, $usuario->uf_base, $industrias ?: null, $afastamentos,
        );
    }

    /** Habilitado para a indústria (id da instalação)? Sem restrição cadastrada vale para todas. */
    public function habilitadoPara(?string $industriaId): bool
    {
        return $this->industrias === null || $industriaId === null || in_array($industriaId, $this->industrias, true);
    }

    /** Está afastado no dia (Y-m-d)? */
    public function afastadoEm(string $data): bool
    {
        foreach ($this->afastamentos as [$inicio, $fim]) {
            if ($data >= $inicio && $data <= $fim) {
                return true;
            }
        }

        return false;
    }

    /** Só o primeiro nome: é o que a indústria vê sobre quem esteve na loja. */
    public function primeiroNome(): string
    {
        return explode(' ', trim($this->nome), 2)[0];
    }
}
