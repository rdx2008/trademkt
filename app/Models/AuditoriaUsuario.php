<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Histórico de alterações do cadastro de um usuário (quem fez o quê e quando). Nunca guarda o
 * CPF nem a senha: só o nome do campo que mudou.
 */
class AuditoriaUsuario extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'auditoria_usuarios';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['detalhes' => 'array', 'created_at' => 'datetime'];
    }

    public const ROTULOS = [
        'criado' => 'Cadastro criado',
        'editado' => 'Cadastro editado',
        'ativado' => 'Reativado',
        'desativado' => 'Desativado',
        'convite_gerado' => 'Convite de primeiro acesso gerado',
        'convite_aceito' => 'Convite aceito (senha criada)',
        'foto_alterada' => 'Foto alterada',
        'cpf_alterado' => 'CPF alterado',
        'cpf_visualizado' => 'CPF consultado',
        'importado' => 'Cadastrado por planilha',
        'habilitacao_alterada' => 'Habilitação por indústria alterada',
        'afastamento_registrado' => 'Afastamento registrado',
        'afastamento_removido' => 'Afastamento removido',
        'ocorrencia_registrada' => 'Ocorrência registrada',
        'aparelho_desconectado' => 'Aparelho desconectado',
    ];

    public function rotulo(): string
    {
        return self::ROTULOS[$this->acao] ?? $this->acao;
    }
}
