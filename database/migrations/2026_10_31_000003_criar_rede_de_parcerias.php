<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agencias_rede', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->unique();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unsignedBigInteger('agencia_local_id');
            $table->text('apresentacao')->nullable();
            $table->string('email_comercial')->nullable();
            $table->boolean('aprovada')->default(false);
            $table->boolean('disponivel')->default(true);
            $table->timestamps();
        });
        Schema::create('agencia_regioes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agencia_rede_id')->constrained('agencias_rede')->cascadeOnDelete();
            $table->string('uf', 2);
            $table->string('cidade', 100)->default('');
            $table->string('cidade_busca', 100)->default('');
            $table->unique(['agencia_rede_id', 'uf', 'cidade_busca']);
        });
        Schema::create('parcerias', function (Blueprint $table) {
            $table->id();
            $table->string('industria_tenant_id');
            $table->foreign('industria_tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unsignedBigInteger('industria_local_id');
            $table->foreignId('agencia_rede_id')->constrained('agencias_rede')->cascadeOnDelete();
            $table->string('status', 20)->default('pendente');
            $table->string('regiao', 200);
            $table->text('mensagem')->nullable();
            $table->text('resposta')->nullable();
            $table->unsignedBigInteger('solicitante_id');
            $table->unsignedBigInteger('respondente_id')->nullable();
            $table->timestamp('respondida_em')->nullable();
            $table->timestamps();
            $table->unique(['industria_tenant_id', 'industria_local_id', 'agencia_rede_id'], 'parceria_unica');
        });
        Schema::create('acessos_parceria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parceria_id')->constrained('parcerias')->cascadeOnDelete();
            $table->unsignedBigInteger('usuario_origem_id');
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expira_em');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acessos_parceria');
        Schema::dropIfExists('parcerias');
        Schema::dropIfExists('agencia_regioes');
        Schema::dropIfExists('agencias_rede');
    }
};
