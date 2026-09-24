<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\Perfil;
use App\Enums\TipoVinculo;
use App\Http\Controllers\Controller;
use App\Models\Agencia;
use App\Models\Industria;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class UsuarioController extends Controller
{
    public function index(Request $request): View
    {
        $eu = $request->user();

        $usuarios = User::query()
            ->visiveisPara($eu)
            ->with(['industria', 'agencia'])
            ->when($request->filled('perfil'), fn ($q) => $q->where('perfil', $request->string('perfil')))
            ->when($request->filled('busca'), function ($q) use ($request) {
                $busca = '%'.$request->string('busca').'%';
                $q->where(fn ($s) => $s->where('nome', 'like', $busca)->orWhere('email', 'like', $busca));
            })
            ->orderBy('nome')
            ->paginate(30)
            ->withQueryString();

        return view('tenant.usuarios.index', [
            'usuarios' => $usuarios,
            'eu' => $eu,
            'perfis' => Perfil::opcoes(),
        ]);
    }

    public function create(Request $request): View
    {
        return view('tenant.usuarios.form', $this->dadosFormulario($request->user(), new User(['ativo' => true])));
    }

    public function store(Request $request): RedirectResponse
    {
        $eu = $request->user();
        $dados = $this->validar($request, $eu);

        User::create($dados);

        return redirect()->route('usuarios.index')->with('status', 'Usuário cadastrado.');
    }

    public function edit(Request $request, User $usuario): View
    {
        abort_unless($request->user()->podeGerenciar($usuario), 403);

        return view('tenant.usuarios.form', $this->dadosFormulario($request->user(), $usuario));
    }

    public function update(Request $request, User $usuario): RedirectResponse
    {
        $eu = $request->user();
        abort_unless($eu->podeGerenciar($usuario), 403);

        $dados = $this->validar($request, $eu, $usuario);
        if (empty($dados['password'])) {
            unset($dados['password']);
        }

        $usuario->update($dados);

        // Desativado: derruba os acessos do app na hora.
        if (! $usuario->ativo) {
            $usuario->tokens()->delete();
        }

        return redirect()->route('usuarios.index')->with('status', 'Usuário atualizado.');
    }

    private function validar(Request $request, User $eu, ?User $usuario = null): array
    {
        $request->merge(['email' => Str::lower((string) $request->input('email'))]);

        $perfisPermitidos = array_map(fn (Perfil $p) => $p->value, $eu->perfil->podeGerenciar());
        $perfil = Perfil::tryFrom((string) $request->input('perfil'));

        $dados = $request->validate([
            'nome' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($usuario?->id)],
            'telefone' => ['nullable', 'string', 'max:20'],
            'perfil' => ['required', Rule::in($perfisPermitidos)],
            'industria_id' => [
                Rule::requiredIf(fn () => $perfil?->exigeIndustria() && $eu->perfil === Perfil::AdminInstalacao),
                'nullable',
                Rule::exists('industrias', 'id')->where('ativo', true),
            ],
            'agencia_id' => [
                Rule::requiredIf(fn () => $perfil?->exigeAgencia() && $eu->perfil === Perfil::AdminInstalacao),
                'nullable',
                Rule::exists('agencias', 'id')->where('ativo', true),
            ],
            'tipo_vinculo' => [
                Rule::requiredIf(fn () => $perfil === Perfil::Promotor),
                'nullable',
                Rule::enum(TipoVinculo::class),
            ],
            'password' => [$usuario ? 'nullable' : 'required', 'confirmed', Password::defaults()],
            'ativo' => ['boolean'],
        ], [
            'perfil.in' => 'Você não pode cadastrar este perfil.',
            'industria_id.required' => 'Escolha a indústria deste usuário.',
            'agencia_id.required' => 'Escolha a agência deste usuário.',
            'tipo_vinculo.required' => 'Informe se o promotor é CLT ou freelancer.',
        ]);

        $dados['ativo'] = $request->boolean('ativo');

        // Vínculo forçado pelo perfil de quem cadastra.
        if ($eu->perfil === Perfil::AdminAgencia) {
            $dados['agencia_id'] = $eu->agencia_id;
        }
        if ($eu->perfil === Perfil::GerenteTrade) {
            $dados['industria_id'] = $eu->industria_id;
        }

        // Limpa vínculos que não fazem sentido para o perfil.
        if (! $perfil->exigeIndustria() && $perfil !== Perfil::AdminInstalacao) {
            $dados['industria_id'] = null;
        }
        if (! $perfil->exigeAgencia() && $perfil !== Perfil::AdminInstalacao) {
            $dados['agencia_id'] = null;
        }
        if ($perfil !== Perfil::Promotor) {
            $dados['tipo_vinculo'] = null;
        }

        return $dados;
    }

    private function dadosFormulario(User $eu, User $usuario): array
    {
        $perfis = [];
        foreach ($eu->perfil->podeGerenciar() as $perfil) {
            $perfis[$perfil->value] = $perfil->label();
        }

        return [
            'usuario' => $usuario,
            'eu' => $eu,
            'perfis' => $perfis,
            'industrias' => Industria::where('ativo', true)->orderBy('nome')->pluck('nome', 'id'),
            'agencias' => Agencia::where('ativo', true)->orderBy('nome')->pluck('nome', 'id'),
            'vinculos' => TipoVinculo::cases(),
        ];
    }
}
