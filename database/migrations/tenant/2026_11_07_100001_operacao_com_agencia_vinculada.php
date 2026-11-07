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
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencias', function (Blueprint $table) {
            $table->dropUnique(['agencia_rede_id']);
            $table->dropColumn('agencia_rede_id');
        });
        Schema::table('agencias', function (Blueprint $table) {
            $table->string('agencia_tenant_id')->nullable()->unique();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('usuario_parceiro_unico');
            $table->dropColumn(['parceiro_origem_tenant', 'parceiro_origem_usuario_id']);
        });

        Schema::table('roteiros', function (Blueprint $table) {
            $table->dropForeign(['promotor_id']);
            $table->dropForeign(['supervisor_id']);
        });
        Schema::table('roteiros', function (Blueprint $table) {
            $table->dropUnique(['promotor_id', 'data']);
        });
        Schema::table('roteiros', function (Blueprint $table) {
            $table->foreignId('agencia_id')->nullable()->after('promotor_id')->constrained('agencias')->nullOnDelete();
            // O mesmo id de promotor pode existir em duas agências: o roteiro é por agência.
            $table->unique(['agencia_id', 'promotor_id', 'data'], 'roteiro_unico');
        });

        Schema::table('demandas', function (Blueprint $table) {
            $table->dropForeign(['solicitante_id']);
            $table->dropForeign(['liberada_por']);
        });
        Schema::table('demandas', function (Blueprint $table) {
            $table->unsignedBigInteger('solicitante_agencia_id')->nullable()->after('solicitante_id');
        });

        // No MySQL a chave estrangeira usa o índice único: ela sai primeiro.
        Schema::table('alertas', function (Blueprint $table) {
            $table->dropForeign(['usuario_id']);
        });
        Schema::table('alertas', function (Blueprint $table) {
            $table->dropUnique(['usuario_id', 'chave']);
        });
        Schema::table('alertas', function (Blueprint $table) {
            $table->unsignedBigInteger('agencia_id')->nullable()->after('usuario_id');
            $table->unique(['agencia_id', 'usuario_id', 'chave'], 'alerta_unico');
        });
    }

    public function down(): void
    {
        Schema::table('alertas', function (Blueprint $table) {
            $table->dropUnique('alerta_unico');
            $table->dropColumn('agencia_id');
            $table->unique(['usuario_id', 'chave']);
        });
        Schema::table('demandas', fn (Blueprint $table) => $table->dropColumn('solicitante_agencia_id'));
        Schema::table('roteiros', function (Blueprint $table) {
            $table->dropUnique('roteiro_unico');
            $table->dropConstrainedForeignId('agencia_id');
            $table->unique(['promotor_id', 'data']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->string('parceiro_origem_tenant')->nullable();
            $table->unsignedBigInteger('parceiro_origem_usuario_id')->nullable();
            $table->unique(['parceiro_origem_tenant', 'parceiro_origem_usuario_id'], 'usuario_parceiro_unico');
        });
        Schema::table('agencias', function (Blueprint $table) {
            $table->dropUnique(['agencia_tenant_id']);
            $table->dropColumn('agencia_tenant_id');
            $table->unsignedBigInteger('agencia_rede_id')->nullable()->unique();
        });
    }
};
