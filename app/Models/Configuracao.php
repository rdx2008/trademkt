<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Configuração da instalação (chave/valor). Fica no banco do cliente.
 */
class Configuracao extends Model
{
    protected $table = 'configuracoes';

    protected $fillable = ['chave', 'valor'];

    /** Todas as configurações como chave => valor. */
    public static function todas(): array
    {
        return static::query()->pluck('valor', 'chave')->all();
    }

    public static function gravar(string $chave, ?string $valor): void
    {
        if ($valor === null || $valor === '') {
            static::where('chave', $chave)->delete();

            return;
        }

        static::updateOrCreate(['chave' => $chave], ['valor' => $valor]);
    }

    /** Data da última alteração de qualquer configuração (usada para versionar arquivos em cache). */
    public static function ultimaAlteracao(): ?string
    {
        return static::query()->max('updated_at');
    }
}
