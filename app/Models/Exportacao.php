<?php

namespace App\Models;

use App\Support\Contexto;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Exportação em segundo plano (fila de downloads). */
class Exportacao extends Model
{
    protected $table = 'exportacoes';

    protected $guarded = ['id'];

    public const TIPOS = ['indicadores' => 'Indicadores (Excel)', 'coletas' => 'Resultados das coletas (CSV)', 'book' => 'Book de fotos (ZIP)'];

    public const DIAS_DE_VALIDADE = 7;

    protected function casts(): array
    {
        return ['parametros' => 'array', 'pronta_em' => 'datetime', 'expira_em' => 'datetime'];
    }

    /** Exportações de quem está usando o sistema agora. */
    public function scopeDoUsuario(Builder $query, User $usuario): Builder
    {
        $agencia = Contexto::agenciaLocal()?->id ?? $usuario->agencia_id;

        return $query->where('usuario_id', $usuario->id)->where(fn ($q) => $agencia === null ? $q->whereNull('agencia_id') : $q->where('agencia_id', $agencia));
    }

    public function expirou(): bool
    {
        return $this->expira_em !== null && $this->expira_em->isPast();
    }

    public function disponivel(): bool
    {
        return $this->estado === 'pronto' && ! $this->expirou();
    }

    public function rotuloTipo(): string
    {
        return self::TIPOS[$this->tipo] ?? $this->tipo;
    }

    /** Usuário "de passagem" refeito a partir do que foi guardado, para o escopo de visibilidade no job. */
    public function usuarioDoEscopo(): User
    {
        $u = $this->parametros['usuario'];

        return (new User)->forceFill([
            'id' => $u['id'], 'nome' => $u['nome'], 'perfil' => $u['perfil'],
            'agencia_id' => $u['agencia_id'], 'industria_id' => $u['industria_id'],
        ]);
    }
}
