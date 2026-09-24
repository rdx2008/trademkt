<?php

namespace App\Console\Commands;

use App\Services\ProvisionarCliente;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class ClienteCriar extends Command
{
    protected $signature = 'cliente:criar
        {id : Identificador do cliente (ex.: gaboardi). Vira o nome do banco e da pasta}
        {--nome= : Nome da empresa}
        {--tipo= : agencia ou industria}
        {--dominio=* : Domínio ou subdomínio (pode repetir)}
        {--codigo= : Código que o promotor digita no app (padrão: o id)}
        {--admin-nome= : Nome do primeiro admin}
        {--admin-email= : E-mail do primeiro admin}
        {--admin-senha= : Senha do admin (se omitida, é gerada)}';

    protected $description = 'Cria um cliente: banco, pasta, domínios e primeiro admin';

    public function handle(ProvisionarCliente $provisionar): int
    {
        $dados = [
            'id' => $this->argument('id'),
            'nome' => $this->option('nome') ?? $this->ask('Nome da empresa'),
            'tipo' => $this->option('tipo') ?? $this->choice('Tipo de instalação', ['agencia', 'industria']),
            'dominios' => $this->option('dominio') ?: [$this->ask('Domínio (ex.: trade.gaboardi.com.br)')],
            'codigo' => $this->option('codigo'),
            'admin_nome' => $this->option('admin-nome') ?? $this->ask('Nome do admin'),
            'admin_email' => $this->option('admin-email') ?? $this->ask('E-mail do admin'),
            'admin_senha' => $this->option('admin-senha'),
        ];

        try {
            $resultado = $provisionar->executar($dados);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $mensagens) {
                foreach ($mensagens as $mensagem) {
                    $this->error($mensagem);
                }
            }

            return self::FAILURE;
        }

        $tenant = $resultado['tenant'];

        $this->info("Cliente {$tenant->nome} criado.");
        $this->table(['Campo', 'Valor'], [
            ['ID', $tenant->id],
            ['Tipo', $tenant->tipo->label()],
            ['Código no app', $tenant->codigo],
            ['Banco', $tenant->database()->getName()],
            ['Pasta', 'storage/clientes/'.$tenant->id],
            ['URL', $tenant->url()],
            ['Admin', $resultado['admin_email']],
            ['Senha', $resultado['admin_senha']],
        ]);
        $this->warn('Adicione o(s) domínio(s) no app do Coolify para gerar o SSL.');

        return self::SUCCESS;
    }
}
