<?php

namespace App\Console\Commands;

use App\Models\Admin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AdminCriar extends Command
{
    protected $signature = 'admin:criar {--nome=} {--email=} {--senha=}';

    protected $description = 'Cria um super admin do painel central';

    public function handle(): int
    {
        $dados = [
            'nome' => $this->option('nome') ?? $this->ask('Nome'),
            'email' => Str::lower($this->option('email') ?? $this->ask('E-mail')),
            'password' => $this->option('senha') ?? $this->secret('Senha (mín. 8)'),
        ];

        $validacao = Validator::make($dados, [
            'nome' => ['required', 'max:120'],
            'email' => ['required', 'email', 'unique:admins,email'],
            'password' => ['required', 'min:8'],
        ]);

        if ($validacao->fails()) {
            $this->error($validacao->errors()->first());

            return self::FAILURE;
        }

        Admin::create($dados);
        $this->info('Super admin criado.');

        return self::SUCCESS;
    }
}
