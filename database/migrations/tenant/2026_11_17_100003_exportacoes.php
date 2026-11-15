<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Fila de downloads: exportações grandes geradas em segundo plano, com link privado por 7 dias.
        // O dono é identificado por (agencia_id, usuario_id), como nos alertas: ids de bancos diferentes
        // podem coincidir. `parametros.usuario` guarda o essencial do perfil para refazer o escopo no job.
        Schema::create('exportacoes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('agencia_id')->nullable();
            $table->unsignedBigInteger('usuario_id');
            $table->string('tipo', 20);
            $table->json('parametros');
            $table->string('estado', 12)->default('pendente');
            $table->string('nome', 150)->nullable();
            $table->string('arquivo')->nullable();
            $table->unsignedBigInteger('tamanho')->nullable();
            $table->text('erro')->nullable();
            $table->dateTime('pronta_em')->nullable();
            $table->dateTime('expira_em')->nullable();
            $table->timestamps();
            $table->index(['agencia_id', 'usuario_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exportacoes');
    }
};
