<?php

namespace App\Models;

use App\Enums\CanalPdv;
use App\Enums\Perfil;
use App\Enums\TipoInstalacao;
use App\Rules\Cnpj;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ponto de venda (loja do supermercado).
 *
 * @property string $cnpj Só dígitos
 * @property ?CanalPdv $canal
 * @property ?float $lat
 * @property ?float $lng
 * @property int $raio_m Raio de check-in em metros (150 a 300)
 */
class Pdv extends Model
{
    public const RAIO_PADRAO = 200;

    public const RAIO_MINIMO = 150;

    public const RAIO_MAXIMO = 300;

    protected $table = 'pdvs';

    protected $fillable = [
        'cnpj', 'nome', 'rede_id', 'canal', 'cep', 'logradouro', 'numero', 'complemento', 'bairro',
        'cidade', 'uf', 'lat', 'lng', 'coordenadas_origem', 'raio_m', 'regiao_id', 'ativo',
    ];

    protected $attributes = [
        'raio_m' => self::RAIO_PADRAO,
        'ativo' => true,
    ];

    protected function casts(): array
    {
        return [
            'canal' => CanalPdv::class,
            'lat' => 'float',
            'lng' => 'float',
            'raio_m' => 'integer',
            'ativo' => 'boolean',
        ];
    }

    protected function cnpj(): Attribute
    {
        return Attribute::set(fn (?string $valor) => Cnpj::limpar($valor));
    }

    protected function cep(): Attribute
    {
        return Attribute::set(fn (?string $valor) => preg_replace('/\D/', '', (string) $valor) ?: null);
    }

    protected function uf(): Attribute
    {
        return Attribute::set(fn (?string $valor) => $valor ? mb_strtoupper(trim($valor)) : null);
    }

    public function rede(): BelongsTo
    {
        return $this->belongsTo(Rede::class);
    }

    public function regiao(): BelongsTo
    {
        return $this->belongsTo(Regiao::class);
    }

    public function contratos(): HasMany
    {
        return $this->hasMany(ContratoVisita::class);
    }

    /** Gerentes do supermercado vinculados à loja. */
    public function gerentes(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'pdv_usuarios')->withTimestamps();
    }

    /** Representantes comerciais que têm a loja na carteira. */
    public function representantes(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'representante_pdv')->withTimestamps();
    }

    public function cnpjFormatado(): string
    {
        return Cnpj::formatar($this->cnpj);
    }

    /** Endereço em uma linha, usado no geocoding e nas listagens. */
    public function enderecoCompleto(): string
    {
        $rua = trim(implode(', ', array_filter([$this->logradouro, $this->numero])));
        $cidade = trim(implode(' - ', array_filter([$this->cidade, $this->uf])));
        $cep = $this->cep ? substr($this->cep, 0, 5).'-'.substr($this->cep, 5) : null;

        return implode(', ', array_filter([$rua, $this->bairro, $cidade, $cep, $this->cidade ? 'Brasil' : null]));
    }

    public function temCoordenadas(): bool
    {
        return $this->lat !== null && $this->lng !== null;
    }

    /**
     * PDVs que o usuário pode ver.
     * Na instalação de agência, a indústria só enxerga os PDVs com contrato ativo da sua marca.
     */
    public function scopeVisiveisPara(Builder $query, User $usuario): Builder
    {
        return match ($usuario->perfil) {
            Perfil::AdminInstalacao, Perfil::AdminAgencia, Perfil::Supervisor => $query,
            Perfil::GerenteTrade, Perfil::Representante => tenant()?->tipo === TipoInstalacao::Agencia
                ? $query->whereHas('contratos', fn (Builder $c) => $c->ativos()->where('industria_id', $usuario->industria_id))
                : $query,
            Perfil::GerentePdv => $query->whereHas('gerentes', fn (Builder $u) => $u->whereKey($usuario->getKey())),
            default => $query->whereRaw('1 = 0'),
        };
    }
}
