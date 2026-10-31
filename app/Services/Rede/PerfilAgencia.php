<?php

namespace App\Services\Rede;

use App\Models\Rede\AgenciaRede;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PerfilAgencia
{
    public const UFS = ['AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'];

    public const ESTADOS = [
        'AC' => 'Acre', 'AL' => 'Alagoas', 'AP' => 'Amapá', 'AM' => 'Amazonas',
        'BA' => 'Bahia', 'CE' => 'Ceará', 'DF' => 'Distrito Federal', 'ES' => 'Espírito Santo',
        'GO' => 'Goiás', 'MA' => 'Maranhão', 'MT' => 'Mato Grosso', 'MS' => 'Mato Grosso do Sul',
        'MG' => 'Minas Gerais', 'PA' => 'Pará', 'PB' => 'Paraíba', 'PR' => 'Paraná',
        'PE' => 'Pernambuco', 'PI' => 'Piauí', 'RJ' => 'Rio de Janeiro', 'RN' => 'Rio Grande do Norte',
        'RS' => 'Rio Grande do Sul', 'RO' => 'Rondônia', 'RR' => 'Roraima', 'SC' => 'Santa Catarina',
        'SP' => 'São Paulo', 'SE' => 'Sergipe', 'TO' => 'Tocantins',
    ];

    public function salvar(AgenciaRede $perfil, array $dados, bool $central): void
    {
        $validados = Validator::make($dados, [
            'apresentacao' => ['nullable', 'string', 'max:2000'],
            'email_comercial' => ['nullable', 'email', 'max:255'],
            'regioes' => ['required', 'string', 'max:6000'],
            'disponivel' => ['sometimes', 'boolean'],
            'aprovada' => ['sometimes', 'boolean'],
        ])->validate();
        $linhas = preg_split('/\r?\n/', trim($validados['regioes']), -1, PREG_SPLIT_NO_EMPTY);
        if (count($linhas) > 50) {
            throw ValidationException::withMessages(['regioes' => 'Informe no máximo 50 regiões.']);
        }
        $regioes = [];
        foreach ($linhas as $linha) {
            [$uf, $cidade] = array_pad(explode(':', trim($linha), 2), 2, '');
            $uf = strtoupper(trim($uf));
            $cidade = trim($cidade);
            Validator::make(['uf' => $uf, 'cidade' => $cidade], ['uf' => [Rule::in(self::UFS)], 'cidade' => ['string', 'max:100']])->validate();
            $busca = Str::lower(Str::ascii($cidade));
            $regioes[$uf.':'.$busca] = ['uf' => $uf, 'cidade' => $cidade, 'cidade_busca' => $busca];
        }
        if (! $regioes) {
            throw ValidationException::withMessages(['regioes' => 'Informe pelo menos uma região atendida.']);
        }
        DB::connection(config('tenancy.database.central_connection'))->transaction(function () use ($perfil, $validados, $dados, $regioes, $central) {
            $perfil->apresentacao = $validados['apresentacao'] ?? null;
            $perfil->email_comercial = $validados['email_comercial'] ?? null;
            $perfil->disponivel = ! empty($dados['disponivel']);
            if ($central) {
                $perfil->aprovada = ! empty($dados['aprovada']);
            }
            $perfil->save();
            $perfil->regioes()->delete();
            $perfil->regioes()->createMany(array_values($regioes));
        });
    }
}
