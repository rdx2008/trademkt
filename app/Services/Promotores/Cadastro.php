<?php

namespace App\Services\Promotores;

use App\Enums\Perfil;
use App\Models\Agencia;
use App\Models\AuditoriaUsuario;
use App\Models\Convite;
use App\Models\User;
use App\Rules\Cpf;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Cadastro dos promotores da agência: dados pessoais (CPF criptografado, foto), convite de
 * primeiro acesso e histórico de alterações. O promotor nunca recebe senha pronta: o admin
 * manda o link do convite e ele cria a própria.
 */
class Cadastro
{
    public const VALIDADE_CONVITE_DIAS = 7;

    /** Campos comparados para o histórico de alterações (o CPF e a foto têm registro próprio). */
    private const CAMPOS = ['nome', 'email', 'telefone', 'tipo_vinculo', 'cidade_base', 'uf_base', 'observacoes'];

    /**
     * @param  array<string, mixed>  $dados  nome, email, telefone, cpf, tipo_vinculo, cidade_base, uf_base, observacoes
     * @return array{usuario: User, convite: string} o link do convite (aparece só agora)
     */
    public function criar(array $dados, ?UploadedFile $foto, ?User $autor, string $origem = 'criado'): array
    {
        return DB::transaction(function () use ($dados, $foto, $autor, $origem) {
            $usuario = new User($this->preencher($dados));
            $usuario->forceFill([
                'perfil' => Perfil::Promotor,
                'agencia_id' => $this->agenciaId(),
                'password' => Str::random(48), // sem senha própria até aceitar o convite
                'ativo' => true,
            ])->save();

            if ($foto) {
                $this->guardarFoto($usuario, $foto);
            }
            $this->auditar($usuario, $origem, $autor);

            return ['usuario' => $usuario, 'convite' => $this->gerarConvite($usuario, $autor, false)];
        });
    }

    /** @param  array<string, mixed>  $dados */
    public function atualizar(User $usuario, array $dados, ?UploadedFile $foto, ?User $autor): void
    {
        DB::transaction(function () use ($usuario, $dados, $foto, $autor) {
            $antes = $usuario->only(self::CAMPOS);
            $cpfAntes = $usuario->cpf;
            $usuario->fill($this->preencher($dados));
            $mudou = array_values(array_filter(self::CAMPOS, fn ($c) => (string) ($antes[$c] instanceof \BackedEnum ? $antes[$c]->value : $antes[$c]) !== (string) ($usuario->{$c} instanceof \BackedEnum ? $usuario->{$c}->value : $usuario->{$c})));
            $cpfMudou = $usuario->cpf !== $cpfAntes;
            $usuario->save();

            if ($foto) {
                $this->guardarFoto($usuario, $foto);
            }
            if ($mudou) {
                $this->auditar($usuario, 'editado', $autor, ['campos' => $mudou]);
            }
            if ($cpfMudou) {
                $this->auditar($usuario, 'cpf_alterado', $autor);
            }
        });
    }

    public function alternarAtivo(User $usuario, bool $ativo, ?User $autor): void
    {
        if ($usuario->ativo === $ativo) {
            return;
        }
        $usuario->forceFill(['ativo' => $ativo])->save();
        // Desativado: derruba o app e cancela convites ainda abertos.
        if (! $ativo) {
            $usuario->tokens()->delete();
            $usuario->convites()->whereNull('usado_em')->delete();
        }
        $this->auditar($usuario, $ativo ? 'ativado' : 'desativado', $autor);
    }

    /**
     * Novo convite (também serve para redefinir a senha). Os anteriores deixam de valer.
     *
     * @return string o link completo
     */
    public function gerarConvite(User $usuario, ?User $autor, bool $auditar = true): string
    {
        $usuario->convites()->whereNull('usado_em')->delete();
        $token = Str::random(48);
        $usuario->convites()->create([
            'token_hash' => hash('sha256', $token),
            'expira_em' => now()->addDays(self::VALIDADE_CONVITE_DIAS),
            'criado_por' => $autor?->id,
        ]);
        if ($auditar) {
            $this->auditar($usuario, 'convite_gerado', $autor);
        }

        return route('convite.mostrar', $token);
    }

    public function conviteDoToken(string $token): ?Convite
    {
        return Convite::with('usuario')->where('token_hash', hash('sha256', $token))->first();
    }

    /** Aceita o convite: grava a senha escolhida, o aceite do aviso de privacidade e encerra o link. */
    public function aceitarConvite(Convite $convite, string $senha): void
    {
        DB::transaction(function () use ($convite, $senha) {
            $usuario = $convite->usuario;
            $usuario->forceFill(['password' => $senha, 'aceite_privacidade_em' => now()])->save();
            $usuario->tokens()->delete();
            $convite->forceFill(['usado_em' => now()])->save();
            $this->auditar($usuario, 'convite_aceito', null);
        });
    }

    public function auditar(User $usuario, string $acao, ?User $autor, ?array $detalhes = null): void
    {
        AuditoriaUsuario::create(['usuario_id' => $usuario->id, 'autor_id' => $autor?->id, 'acao' => $acao, 'detalhes' => $detalhes]);
    }

    /** Registra que o CPF foi consultado (no máximo uma vez a cada dez minutos por pessoa). */
    public function registrarConsultaDoCpf(User $usuario, User $autor): void
    {
        $recente = AuditoriaUsuario::where('usuario_id', $usuario->id)->where('autor_id', $autor->id)->where('acao', 'cpf_visualizado')
            ->where('created_at', '>', now()->subMinutes(10))->exists();
        if (! $recente) {
            $this->auditar($usuario, 'cpf_visualizado', $autor);
        }
    }

    /** Foto do promotor: recortada em quadrado, reduzida e regravada em JPEG (sem metadados). */
    public function guardarFoto(User $usuario, UploadedFile $arquivo): void
    {
        $origem = @imagecreatefromstring((string) file_get_contents($arquivo->getRealPath()));
        if (! $origem) {
            return;
        }
        $l = imagesx($origem);
        $a = imagesy($origem);
        $lado = min($l, $a);
        $destino = imagecreatetruecolor(600, 600);
        imagecopyresampled($destino, $origem, 0, 0, intdiv($l - $lado, 2), intdiv($a - $lado, 2), 600, 600, $lado, $lado);

        ob_start();
        imagejpeg($destino, null, 85);
        $jpeg = (string) ob_get_clean();

        $caminho = 'promotores/'.$usuario->id.'-'.Str::random(10).'.jpg';
        Storage::disk('local')->put($caminho, $jpeg);
        if ($usuario->foto && $usuario->foto !== $caminho) {
            Storage::disk('local')->delete($usuario->foto);
        }
        $usuario->forceFill(['foto' => $caminho])->save();
        $this->auditar($usuario, 'foto_alterada', auth()->user());
    }

    /** @param  array<string, mixed>  $dados */
    private function preencher(array $dados): array
    {
        $campos = [];
        foreach (self::CAMPOS as $campo) {
            if (array_key_exists($campo, $dados)) {
                $valor = is_string($dados[$campo]) ? trim($dados[$campo]) : $dados[$campo];
                $campos[$campo] = $valor === '' ? null : $valor;
            }
        }
        if (isset($campos['email'])) {
            $campos['email'] = Str::lower($campos['email']);
        }
        if (isset($campos['uf_base'])) {
            $campos['uf_base'] = strtoupper($campos['uf_base']);
        }
        if (! empty($dados['cpf'])) {
            $campos['cpf'] = Cpf::limpar($dados['cpf']);
            $campos['cpf_hash'] = Cpf::hash($dados['cpf']);
        }

        return $campos;
    }

    private function agenciaId(): ?int
    {
        return Agencia::where('ativo', true)->orderBy('id')->value('id');
    }
}
