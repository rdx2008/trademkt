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
    ) {}

    public static function deUsuario(User $usuario, int $agenciaId): self
    {
        return new self($usuario->id, $agenciaId, $usuario->nome, $usuario->perfil, $usuario->ativo, $usuario->tipo_vinculo);
    }

    /** Só o primeiro nome: é o que a indústria vê sobre quem esteve na loja. */
    public function primeiroNome(): string
    {
        return explode(' ', trim($this->nome), 2)[0];
    }
}
