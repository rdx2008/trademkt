<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Vínculo indústria–agência criado pelo super admin, no lugar do diretório de agências com
 * solicitação, aceite e token de acesso. A operação (PDVs, contratos, roteiros, visitas) fica no
 * banco da indústria; as pessoas da agência (admin, supervisores e promotores) ficam no banco da
 * agência. Os dados anteriores do diretório eram de teste e são descartados.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('acessos_parceria');
        Schema::dropIfExists('parcerias');
        Schema::dropIfExists('agencia_regioes');
        Schema::dropIfExists('agencias_rede');

        Schema::create('vinculos', function (Blueprint $table) {
            $table->id();
            $table->string('industria_tenant_id');
            $table->foreign('industria_tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->string('agencia_tenant_id');
            $table->foreign('agencia_tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            // Regiões atendidas pela agência nesta indústria: [{uf, cidade}] (informativo)
            $table->json('regioes')->nullable();
            $table->boolean('ativo')->default(true);
            $table->timestamps();
            $table->unique(['industria_tenant_id', 'agencia_tenant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vinculos');
    }
};
