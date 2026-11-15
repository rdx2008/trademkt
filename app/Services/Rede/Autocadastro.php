<?php

namespace App\Services\Rede;

use App\Enums\ModoEmpresa;
use App\Enums\TipoInstalacao;
use App\Models\Tenant;
use App\Models\User;
use App\Rules\Cnpj;
use App\Services\Promotores\Cadastro;
use App\Services\ProvisionarCliente;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Uma empresa cria outra pelo próprio painel (fase 9): a indústria cria a agência interna ou uma
 * agência externa nova; a agência cria uma indústria gerida. Sem o super admin e, por decisão do
 * dono, sem limite no começo.
 *
 * A empresa nova ganha subdomínio do sistema (`<id>.TRADE_DOMINIO_CLIENTES`), herda o fuso de quem
 * criou e tem o admin informado, que entra por um link de primeiro acesso (o mesmo convite dos
 * promotores, válido por 7 dias e de uso único). O CNPJ é obrigatório: é a chave da rede.
 *
 * O provisionamento roda na própria requisição (cria o banco e migra). O subdomínio só responde
 * sem passo manual se o DNS, o roteamento e o certificado curinga estiverem configurados no servidor.
 */
class Autocadastro
{
    public function __construct(private ProvisionarCliente $provisionar, private Cadastro $cadastro) {}

    /**
     * @param  array{nome?: string, cnpj?: string, admin_nome?: string, admin_email?: string}  $dados
     * @return array{tenant: Tenant, convite: string}
     *
     * @throws ValidationException
     */
    public function criar(Tenant $criador, TipoInstalacao $tipo, ModoEmpresa $modo, array $dados): array
    {
        $dados = Validator::make($dados, [
            'nome' => ['required', 'string', 'max:120'],
            'cnpj' => ['required', new Cnpj],
            'admin_nome' => ['required', 'string', 'max:120'],
            'admin_email' => ['required', 'email', 'max:190'],
        ], [], ['admin_nome' => 'nome do responsável', 'admin_email' => 'e-mail do responsável'])->validate();

        $id = $this->identificador($dados['nome']);
        $resultado = $this->provisionar->executar([
            'id' => $id,
            'nome' => $dados['nome'],
            'tipo' => $tipo->value,
            'cnpj' => $dados['cnpj'],
            'dominios' => [str_replace('_', '-', $id).'.'.config('trade.dominio_clientes')],
            'admin_nome' => $dados['admin_nome'],
            'admin_email' => $dados['admin_email'],
            'modo' => $modo->value,
            'dono_tenant_id' => $modo === ModoEmpresa::Propria ? null : $criador->id,
            'origem' => 'autocadastro',
            'criado_por_tenant_id' => $criador->id,
        ]);

        /** @var Tenant $novo */
        $novo = $resultado['tenant'];
        if ($criador->fuso) {
            $novo->fuso = $criador->fuso;
            $novo->save();
        }
        $email = Str::lower($dados['admin_email']);
        $convite = $novo->run(fn () => $this->cadastro->gerarConvite(
            User::where('email', $email)->sole(), null, false, $novo->url(),
        ));

        return ['tenant' => $novo, 'convite' => $convite];
    }

    /** Identificador (e nome do banco) a partir do nome: letras minúsculas, números e _, único. */
    private function identificador(string $nome): string
    {
        $base = Str::of(Str::ascii($nome))->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->limit(28, '')->toString();
        $base = preg_match('/^[a-z]/', $base) ? $base : 'e_'.$base;
        $base = rtrim($base, '_') ?: 'empresa';
        do {
            $id = $base.'_'.Str::lower(Str::random(4));
        } while (Tenant::whereKey($id)->orWhere('codigo', $id)->exists());

        return $id;
    }
}
