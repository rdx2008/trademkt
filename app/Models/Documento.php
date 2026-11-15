<?php

namespace App\Models;

use App\Enums\Perfil;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Arquivo da empresa (contrato, planograma, comunicado), com perfis que podem ver. */
class Documento extends Model
{
    protected $table = 'documentos';

    protected $guarded = ['id'];

    public const CATEGORIAS = ['contrato' => 'Contrato', 'planograma' => 'Planograma', 'comunicado' => 'Comunicado', 'treinamento' => 'Treinamento', 'outro' => 'Outro'];

    /** Extensões aceitas (documentos e imagens; nada executável). */
    public const EXTENSOES = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'csv', 'txt', 'png', 'jpg', 'jpeg', 'zip'];

    /** Perfis que podem ser escolhidos como leitores (o admin da instalação sempre vê). */
    public const PERFIS_LEITORES = [
        'admin_agencia' => 'Admin da agência', 'supervisor' => 'Supervisor', 'gerente_trade' => 'Gerente de trade',
        'representante' => 'Representante', 'promotor' => 'Promotor', 'gerente_pdv' => 'Gerente de supermercado',
    ];

    protected function casts(): array
    {
        return ['perfis' => 'array', 'tamanho' => 'integer'];
    }

    public function visivelPara(User $usuario): bool
    {
        if ($usuario->temPerfil(Perfil::AdminInstalacao)) {
            return true;
        }
        $perfis = $this->perfis ?? [];

        return $perfis === []
            ? in_array($usuario->perfil, [Perfil::AdminAgencia, Perfil::Supervisor, Perfil::GerenteTrade, Perfil::Representante], true)
            : in_array($usuario->perfil->value, $perfis, true);
    }

    public function scopeVisiveisPara(Builder $query, User $usuario): Builder
    {
        if ($usuario->temPerfil(Perfil::AdminInstalacao)) {
            return $query;
        }
        $perfil = $usuario->perfil->value;
        $padrao = in_array($usuario->perfil, [Perfil::AdminAgencia, Perfil::Supervisor, Perfil::GerenteTrade, Perfil::Representante], true);

        return $query->where(function (Builder $q) use ($perfil, $padrao) {
            if ($padrao) {
                $q->whereNull('perfis')->orWhere('perfis', '[]');
            }
            $q->orWhereJsonContains('perfis', $perfil);
        });
    }

    public function rotuloCategoria(): string
    {
        return self::CATEGORIAS[$this->categoria] ?? 'Outro';
    }
}
