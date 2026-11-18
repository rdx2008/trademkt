<?php

namespace App\Services\Rede;

use App\Enums\ModoEmpresa;
use App\Enums\TipoInstalacao;
use App\Models\Tenant;
use App\Models\User;
use App\Rules\Cnpj;
use App\Services\DominiosCliente;
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
    public function __construct(private ProvisionarCliente $provisionar, private Cadastro $cadastro, private DominiosCliente $dominios) {}

    /**
     * @param  array{nome?: string, cnpj?: string, subdominio?: string, admin_nome?: string, admin_email?: string}  $dados
     * @return array{tenant: Tenant, convite: ?string} sem convite na indústria gerida
     *
     * @throws ValidationException
     */
    public function criar(Tenant $criador, TipoInstalacao $tipo, ModoEmpresa $modo, array $dados): array
    {
        $semAdmin = $modo === ModoEmpresa::Gerida;
        if ($semAdmin) {
            // A agência opera pela própria instalação; o admin da indústria nasce inativo e só ganha
            // nome, e-mail e acesso quando a agência entregar o cadastro à indústria.
            $dados['admin_nome'] = 'Administrador (aguardando a indústria)';
            $dados['admin_email'] = 'pendente@'.Str::lower(Str::random(12)).'.invalid';
        }
        $subdominio = $dados['subdominio'] ?? null;
        $dados = Validator::make($dados, [
            'nome' => ['required', 'string', 'max:120'],
            'cnpj' => ['required', new Cnpj],
            'admin_nome' => ['required', 'string', 'max:120'],
            'admin_email' => ['required', 'email', 'max:190'],
        ], [], ['admin_nome' => 'nome do responsável', 'admin_email' => 'e-mail do responsável'])->validate();

        // O prefixo do endereço é escolhido por quem cria (ex.: minhaagencia.bra.net.br) e vira também o
        // código que o promotor digita no app e o identificador do banco.
        $prefixo = $this->dominios->validarPrefixo($subdominio);
        $id = str_replace('-', '_', $prefixo);
        $resultado = $this->provisionar->executar([
            'id' => $id,
            'codigo' => $prefixo,
            'nome' => $dados['nome'],
            'tipo' => $tipo->value,
            'cnpj' => $dados['cnpj'],
            'dominios' => [$this->dominios->enderecoDoPrefixo($prefixo)],
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
        if ($semAdmin) {
            $novo->run(fn () => User::where('email', $email)->update(['ativo' => false]));

            return ['tenant' => $novo, 'convite' => null];
        }
        $convite = $novo->run(fn () => $this->cadastro->gerarConvite(
            User::where('email', $email)->sole(), null, false, $novo->url(),
        ));

        return ['tenant' => $novo, 'convite' => $convite];
    }
}
