<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Meus documentos: arquivos da empresa (contratos, planogramas, comunicados) com permissão por perfil.
        // `perfis` vazio = todos os perfis que acompanham a operação (nunca o promotor sem estar na lista).
        Schema::create('documentos', function (Blueprint $table) {
            $table->id();
            $table->string('titulo', 150);
            $table->string('descricao', 500)->nullable();
            $table->string('categoria', 40)->nullable();
            $table->string('arquivo');
            $table->string('nome_original', 200);
            $table->string('mime', 100)->nullable();
            $table->unsignedBigInteger('tamanho');
            $table->json('perfis')->nullable();
            $table->unsignedBigInteger('enviado_por')->nullable();
            $table->string('enviado_por_nome', 120)->nullable();
            $table->timestamps();
            $table->index('categoria');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documentos');
    }
};
