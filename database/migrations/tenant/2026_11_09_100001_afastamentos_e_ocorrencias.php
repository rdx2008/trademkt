<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ausências planejadas do promotor (férias, atestado, folga...). Sem FK para o autor: o usuário pode sair.
        Schema::create('afastamentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('users')->cascadeOnDelete();
            $table->string('tipo', 20);
            $table->date('inicio');
            $table->date('fim');
            $table->string('observacao', 300)->nullable();
            $table->unsignedBigInteger('criado_por')->nullable();
            $table->timestamps();
            $table->index(['usuario_id', 'inicio', 'fim']);
        });

        // Registro de fatos sobre o promotor (elogio, atraso, falta...). Só a gestão da agência vê.
        Schema::create('ocorrencias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('users')->cascadeOnDelete();
            $table->string('tipo', 20);
            $table->date('data');
            $table->text('descricao');
            $table->unsignedBigInteger('registrado_por')->nullable();
            $table->timestamps();
            $table->index(['usuario_id', 'data']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ocorrencias');
        Schema::dropIfExists('afastamentos');
    }
};
