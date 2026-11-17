<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Identidade do promotor no painel central: a mesma pessoa atende várias agências e, numa
        // fase futura, a rede aberta de freelancers. O CPF só existe aqui como hash.
        Schema::create('promotores_globais', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 40)->unique();
            $table->string('cpf_hash', 64)->unique();
            $table->string('nome', 120);
            $table->string('cidade_base', 80)->nullable();
            $table->string('uf_base', 2)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedSmallInteger('raio_km')->nullable();
            // Futuro: aceita trabalhos avulsos da rede aberta.
            $table->boolean('disponivel_rede')->default(false);
            $table->timestamps();
        });

        // Consentimentos por finalidade e versão do texto, com prova (data, IP, aparelho) e revogação.
        Schema::create('consentimentos_promotor', function (Blueprint $table) {
            $table->id();
            $table->string('promotor_global_codigo', 40)->index();
            $table->string('tenant_id')->nullable();
            // rede_compartilhada (termo de uso compartilhado da rede da plataforma)
            $table->string('finalidade', 40);
            $table->string('versao', 20);
            // link_agencia | convite | painel
            $table->string('origem', 20);
            $table->string('ip', 45)->nullable();
            $table->string('aparelho', 255)->nullable();
            $table->timestamp('aceito_em');
            $table->timestamp('revogado_em')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consentimentos_promotor');
        Schema::dropIfExists('promotores_globais');
    }
};
