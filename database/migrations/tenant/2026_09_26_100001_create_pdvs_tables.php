<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Fase 2.1: regiões, redes, PDVs e vínculos de usuários com PDVs.
 * contratos_visita nasce aqui porque a visibilidade de PDVs para a indústria
 * depende dele; o cadastro dos contratos é o item 2.4.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regioes', function (Blueprint $table) {
            $table->id();
            $table->string('nome', 80)->unique();
            $table->boolean('ativo')->default(true);
            $table->timestamps();
        });

        Schema::create('redes', function (Blueprint $table) {
            $table->id();
            $table->string('nome', 80)->unique();
            $table->boolean('ativo')->default(true);
            $table->timestamps();
        });

        Schema::create('pdvs', function (Blueprint $table) {
            $table->id();
            $table->string('cnpj', 14)->unique(); // só dígitos
            $table->string('nome', 150);
            $table->foreignId('rede_id')->nullable()->constrained('redes')->nullOnDelete();
            $table->string('canal', 20)->nullable()->index();
            $table->string('cep', 8)->nullable();
            $table->string('logradouro', 150)->nullable();
            $table->string('numero', 20)->nullable();
            $table->string('complemento', 80)->nullable();
            $table->string('bairro', 80)->nullable();
            $table->string('cidade', 80)->nullable()->index();
            $table->char('uf', 2)->nullable()->index();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            // geocoding | manual | planilha
            $table->string('coordenadas_origem', 20)->nullable();
            $table->unsignedSmallInteger('raio_m')->default(200);
            $table->foreignId('regiao_id')->nullable()->constrained('regioes')->nullOnDelete();
            $table->boolean('ativo')->default(true);
            $table->timestamps();
        });

        // Gerente do supermercado ↔ PDV(s)
        Schema::create('pdv_usuarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pdv_id')->constrained('pdvs')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['pdv_id', 'user_id']);
        });

        // Carteira de PDVs do representante comercial
        Schema::create('representante_pdv', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('pdv_id')->constrained('pdvs')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'pdv_id']);
        });

        Schema::create('contratos_visita', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pdv_id')->constrained('pdvs')->cascadeOnDelete();
            $table->foreignId('industria_id')->constrained('industrias')->cascadeOnDelete();
            $table->foreignId('agencia_id')->constrained('agencias')->cascadeOnDelete();
            $table->unsignedTinyInteger('frequencia_semana');
            $table->json('dias'); // 1 = segunda ... 7 = domingo (ISO)
            $table->date('inicio');
            $table->date('fim')->nullable();
            $table->boolean('ativo')->default(true);
            $table->timestamps();
            $table->index(['industria_id', 'ativo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contratos_visita');
        Schema::dropIfExists('representante_pdv');
        Schema::dropIfExists('pdv_usuarios');
        Schema::dropIfExists('pdvs');
        Schema::dropIfExists('redes');
        Schema::dropIfExists('regioes');
    }
};
