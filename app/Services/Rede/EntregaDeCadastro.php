<?php

namespace App\Services\Rede;

use App\Enums\ModoEmpresa;
use App\Enums\Perfil;
use App\Models\Industria;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Promotores\Cadastro;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A indústria assume o cadastro que a agência montou para ela (fase 9, item 9.6). Sem cadastro
 * público, quem dispara é a agência gestora, informando o responsável da indústria: a empresa passa
 * a ser independente (modo própria, sem dono), os dados ficam como estão, o vínculo com a agência
 * continua ativo e o responsável recebe um link de primeiro acesso como admin da instalação.
 */
class EntregaDeCadastro
{
    public function __construct(private Cadastro $cadastro) {}

    /**
     * @return string link de primeiro acesso do responsável
     *
     * @throws ValidationException
     */
    public function entregar(Tenant $industria, Tenant $agencia, string $nome, string $email): string
    {
        if ($industria->modo !== ModoEmpresa::Gerida || $industria->dono_tenant_id !== $agencia->id) {
            throw ValidationException::withMessages(['industria' => 'Só a agência gestora entrega o cadastro desta indústria.']);
        }
        $dados = Validator::make(['admin_nome' => $nome, 'admin_email' => $email], [
            'admin_nome' => ['required', 'string', 'max:120'],
            'admin_email' => ['required', 'email', 'max:190'],
        ], [], ['admin_nome' => 'nome do responsável', 'admin_email' => 'e-mail do responsável'])->validate();
        $email = Str::lower($dados['admin_email']);

        $link = $industria->run(function () use ($dados, $email, $industria) {
            $admin = User::where('perfil', Perfil::AdminInstalacao->value)->orderBy('id')->first()
                ?? new User(['perfil' => Perfil::AdminInstalacao]);
            if (User::where('email', $email)->where('id', '!=', $admin->id ?? 0)->exists()) {
                throw ValidationException::withMessages(['admin_email' => 'Este e-mail já é de outro usuário desta indústria.']);
            }
            $admin->forceFill([
                'nome' => $dados['admin_nome'],
                'email' => $email,
                'perfil' => Perfil::AdminInstalacao,
                'industria_id' => $admin->industria_id ?? Industria::orderBy('id')->value('id'),
                'password' => $admin->password ?? Str::random(48),
                'ativo' => true,
            ])->save();

            return $this->cadastro->gerarConvite($admin, null, false, $industria->url());
        });

        $industria->forceFill(['modo' => ModoEmpresa::Propria, 'dono_tenant_id' => null])->save();
        $industria->assumida_em = now()->toIso8601String();
        $industria->assumida_de = $agencia->id;
        $industria->save();

        return $link;
    }
}
