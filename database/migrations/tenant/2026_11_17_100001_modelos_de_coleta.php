<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Fase 10: modelos de coleta configuráveis (formulários que rodam dentro da visita, offline). A
 * definição dos campos fica em JSON no próprio modelo, com `versao`: alterar o modelo gera nova
 * versão e as respostas guardam a versão em que foram dadas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modelos_coleta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('industria_id')->constrained('industrias')->cascadeOnDelete();
            $table->string('nome', 120);
            // preco | validade | estoque | share | checklist | livre
            $table->string('tipo', 20)->default('livre');
            $table->unsignedInteger('versao')->default(1);
            $table->json('campos');
            // Escopo opcional: restringe a redes de supermercados (ids). Vazio = todas as lojas da indústria.
            $table->json('redes')->nullable();
            // Validade: avisa quando algum produto respondido vence em até N dias (nulo = sem alerta).
            $table->unsignedSmallInteger('alerta_dias')->nullable();
            $table->boolean('ativo')->default(true);
            $table->timestamps();
            $table->index(['industria_id', 'ativo']);
        });

        // Definição de cada versão do modelo, para interpretar respostas antigas depois de uma edição.
        Schema::create('modelo_coleta_versoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('modelo_id')->constrained('modelos_coleta')->cascadeOnDelete();
            $table->unsignedInteger('versao');
            $table->json('campos');
            $table->timestamp('created_at')->nullable();
            $table->unique(['modelo_id', 'versao']);
        });

        Schema::create('coleta_respostas', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('visita_id')->constrained('visitas')->cascadeOnDelete();
            $table->foreignId('modelo_id')->constrained('modelos_coleta')->cascadeOnDelete();
            $table->unsignedInteger('versao');
            // Modelos "por SKU": uma linha por SKU respondido; nos demais, nulo.
            $table->foreignId('sku_id')->nullable()->constrained('skus')->nullOnDelete();
            $table->json('respostas');
            $table->dateTime('respondida_em');
            $table->dateTime('recebida_em')->nullable();
            $table->string('hash', 64);
            $table->timestamps();
            $table->index(['visita_id', 'modelo_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coleta_respostas');
        Schema::dropIfExists('modelo_coleta_versoes');
        Schema::dropIfExists('modelos_coleta');
    }
};
