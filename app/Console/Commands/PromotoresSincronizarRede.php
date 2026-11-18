<?php

namespace App\Console\Commands;

use App\Enums\Perfil;
use App\Enums\TipoInstalacao;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Promotores\IdentidadeGlobal;
use App\Support\Municipios;
use Illuminate\Console\Command;

class PromotoresSincronizarRede extends Command
{
    protected $signature = 'promotores:sincronizar-rede';

    protected $description = 'Liga todos os promotores já cadastrados à identidade central (cidade, raio e agência), para a contagem da rede. Pode rodar de novo à vontade.';

    public function handle(IdentidadeGlobal $identidade): int
    {
        $total = 0;
        foreach (Tenant::where('tipo', TipoInstalacao::Agencia->value)->cursor() as $tenant) {
            try {
                $total += $tenant->run(function () use ($identidade) {
                    $n = 0;
                    foreach (User::where('perfil', Perfil::Promotor->value)->whereNotNull('cpf_hash')->cursor() as $promotor) {
                        // A cidade digitada à mão vira município da lista quando o nome e a UF batem.
                        if (! $promotor->municipio_ibge) {
                            $codigo = Municipios::procurar($promotor->cidade_base, $promotor->uf_base);
                            if ($codigo) {
                                $promotor->forceFill(['municipio_ibge' => $codigo])->save();
                            }
                        }
                        $identidade->vincular($promotor);
                        $n++;
                    }

                    return $n;
                });
            } catch (\Throwable $e) {
                report($e);
                $this->error('Falha em '.$tenant->id.': '.$e->getMessage());
            }
        }
        $this->info($total.' promotor(es) sincronizado(s).');

        return self::SUCCESS;
    }
}
