<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ClienteDominio extends Command
{
    protected $signature = 'cliente:dominio
        {id : Identificador do cliente}
        {dominio : Domínio a adicionar ou remover}
        {--remover : Remove o domínio em vez de adicionar}';

    protected $description = 'Adiciona ou remove um domínio de um cliente';

    public function handle(): int
    {
        $tenant = Tenant::find($this->argument('id'));
        if (! $tenant) {
            $this->error('Cliente não encontrado.');

            return self::FAILURE;
        }

        $dominio = Str::lower(trim($this->argument('dominio')));

        if ($this->option('remover')) {
            if ($tenant->domains()->count() <= 1) {
                $this->error('O cliente precisa manter pelo menos um domínio.');

                return self::FAILURE;
            }
            $tenant->domains()->where('domain', $dominio)->delete();
            $this->info("Domínio {$dominio} removido.");

            return self::SUCCESS;
        }

        $validacao = Validator::make(['dominio' => $dominio], [
            'dominio' => [
                'regex:/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/',
                Rule::unique('domains', 'domain'),
                Rule::notIn(config('tenancy.central_domains')),
            ],
        ]);

        if ($validacao->fails()) {
            $this->error($validacao->errors()->first());

            return self::FAILURE;
        }

        $tenant->domains()->create(['domain' => $dominio]);
        $this->info("Domínio {$dominio} adicionado. Inclua-o também no app do Coolify.");

        return self::SUCCESS;
    }
}
