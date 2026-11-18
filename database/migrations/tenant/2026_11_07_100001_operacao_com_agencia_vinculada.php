<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Promotores e supervisores passam a morar no banco da agência. No banco da indústria, a agência
 * vinculada é uma linha de `agencias` com `agencia_tenant_id`, e as referências a pessoas da
 * agência (promotor do roteiro, supervisor, quem liberou a demanda, destinatário de alerta) guardam
 * o id do usuário no banco da agência, sem chave estrangeira local. `agencia_id` ao lado diz de
 * qual banco é esse id (nulo = usuário desta instalação).
 *
 * Os bancos dos clientes nasceram em momentos diferentes, então tudo que é removido só é
 * removido se existir (índice, chave estrangeira ou coluna).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Diretório antigo de agências: coluna e índice, se houver.
        $this->soltarIndice('agencias', 'agencias_agencia_rede_id_unique');
        $this->soltarColuna('agencias', 'agencia_rede_id');
        if (! Schema::hasColumn('agencias', 'agencia_tenant_id')) {
            Schema::table('agencias', function (Blueprint $table) {
                $table->string('agencia_tenant_id')->nullable()->unique();
            });
        }

        // Acesso de administradores de agência por token (fluxo antigo).
        $this->soltarIndice('users', 'usuario_parceiro_unico');
        $this->soltarColuna('users', 'parceiro_origem_tenant');
        $this->soltarColuna('users', 'parceiro_origem_usuario_id');

        // Roteiros: o promotor é da agência (outro banco). Chave estrangeira sai antes do índice.
        $this->soltarChave('roteiros', 'promotor_id');
        $this->soltarChave('roteiros', 'supervisor_id');
        $this->soltarIndice('roteiros', 'roteiros_promotor_id_data_unique');
        if (! Schema::hasColumn('roteiros', 'agencia_id')) {
            Schema::table('roteiros', function (Blueprint $table) {
                $table->foreignId('agencia_id')->nullable()->after('promotor_id')->constrained('agencias')->nullOnDelete();
            });
        }
        // O mesmo id de promotor pode existir em duas agências: o roteiro é por agência.
        if (! Schema::hasIndex('roteiros', 'roteiro_unico')) {
            Schema::table('roteiros', fn (Blueprint $table) => $table->unique(['agencia_id', 'promotor_id', 'data'], 'roteiro_unico'));
        }

        // Demandas: solicitante e quem liberou podem ser da agência.
        $this->soltarChave('demandas', 'solicitante_id');
        $this->soltarChave('demandas', 'liberada_por');
        if (! Schema::hasColumn('demandas', 'solicitante_agencia_id')) {
            Schema::table('demandas', function (Blueprint $table) {
                $table->unsignedBigInteger('solicitante_agencia_id')->nullable()->after('solicitante_id');
            });
        }

        // Alertas: a chave estrangeira usa o índice único como apoio no MySQL, então sai primeiro.
        $this->soltarChave('alertas', 'usuario_id');
        $this->soltarIndice('alertas', 'alertas_usuario_id_chave_unique');
        if (! Schema::hasColumn('alertas', 'agencia_id')) {
            Schema::table('alertas', function (Blueprint $table) {
                $table->unsignedBigInteger('agencia_id')->nullable()->after('usuario_id');
            });
        }
        if (! Schema::hasIndex('alertas', 'alerta_unico')) {
            Schema::table('alertas', fn (Blueprint $table) => $table->unique(['agencia_id', 'usuario_id', 'chave'], 'alerta_unico'));
        }
    }

    public function down(): void
    {
        $this->soltarIndice('alertas', 'alerta_unico');
        $this->soltarColuna('alertas', 'agencia_id');
        Schema::table('alertas', fn (Blueprint $table) => $table->unique(['usuario_id', 'chave']));

        $this->soltarColuna('demandas', 'solicitante_agencia_id');

        $this->soltarIndice('roteiros', 'roteiro_unico');
        $this->soltarChave('roteiros', 'agencia_id');
        $this->soltarColuna('roteiros', 'agencia_id');
        Schema::table('roteiros', fn (Blueprint $table) => $table->unique(['promotor_id', 'data']));

        Schema::table('users', function (Blueprint $table) {
            $table->string('parceiro_origem_tenant')->nullable();
            $table->unsignedBigInteger('parceiro_origem_usuario_id')->nullable();
            $table->unique(['parceiro_origem_tenant', 'parceiro_origem_usuario_id'], 'usuario_parceiro_unico');
        });

        $this->soltarIndice('agencias', 'agencias_agencia_tenant_id_unique');
        $this->soltarColuna('agencias', 'agencia_tenant_id');
        Schema::table('agencias', fn (Blueprint $table) => $table->unsignedBigInteger('agencia_rede_id')->nullable()->unique());
    }

    private function soltarIndice(string $tabela, string $indice): void
    {
        if (Schema::hasTable($tabela) && Schema::hasIndex($tabela, $indice)) {
            Schema::table($tabela, fn (Blueprint $table) => $table->dropIndex($indice));
        }
    }

    private function soltarChave(string $tabela, string $coluna): void
    {
        if (! Schema::hasTable($tabela)) {
            return;
        }
        foreach (Schema::getForeignKeys($tabela) as $chave) {
            if ($chave['columns'] === [$coluna]) {
                // SQLite só entende a coluna; MySQL, o nome real da chave (que pode variar entre bancos).
                $alvo = Schema::getConnection()->getDriverName() === 'sqlite' ? [$coluna] : $chave['name'];
                Schema::table($tabela, fn (Blueprint $table) => $table->dropForeign($alvo));
            }
        }
    }

    private function soltarColuna(string $tabela, string $coluna): void
    {
        if (Schema::hasColumn($tabela, $coluna)) {
            Schema::table($tabela, fn (Blueprint $table) => $table->dropColumn($coluna));
        }
    }
};
