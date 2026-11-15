<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Fase 9 (rede de empresas), base de dados. Empresas ganham CNPJ (chave entre empresas do mesmo
 * tipo), modo (própria, agência interna de uma indústria ou indústria gerida por uma agência), dono
 * e origem. O vínculo ganha situação (pedido pendente, aceito, recusado, encerrado), quem pediu,
 * relação comercial (prospect, cliente, encerrado) e permissões. Tudo o que existe vira "própria",
 * criado pelo super admin, com vínculos ativos de clientes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('cnpj', 14)->nullable();
            $table->string('modo', 10)->default('propria');
            $table->string('dono_tenant_id')->nullable();
            $table->string('origem', 20)->default('super_admin');
            $table->string('criado_por_tenant_id')->nullable();
            $table->unique(['tipo', 'cnpj']);
            $table->index('dono_tenant_id');
        });

        Schema::table('vinculos', function (Blueprint $table) {
            $table->string('status', 12)->default('ativo');
            $table->string('origem', 20)->default('super_admin');
            $table->string('relacao', 12)->default('cliente');
            $table->json('permissoes')->nullable();
            $table->timestamp('decidido_em')->nullable();
            $table->index('status');
        });

        DB::table('vinculos')->where('ativo', false)->update(['status' => 'encerrado']);
    }

    public function down(): void
    {
        Schema::table('vinculos', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn(['status', 'origem', 'relacao', 'permissoes', 'decidido_em']);
        });
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropUnique(['tipo', 'cnpj']);
            $table->dropIndex(['dono_tenant_id']);
            $table->dropColumn(['cnpj', 'modo', 'dono_tenant_id', 'origem', 'criado_por_tenant_id']);
        });
    }
};
