<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Fase 2.5: roteiros (dia de trabalho de um promotor) e visitas.
 * A visita nasce do contrato sem promotor (roteiro_id nulo) e o supervisor da agência atribui.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roteiros', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotor_id')->constrained('users')->cascadeOnDelete();
            $table->date('data');
            // Quem montou/atribuiu (supervisor ou admin da agência)
            $table->foreignId('supervisor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['promotor_id', 'data']);
            $table->index('data');
        });

        Schema::create('visitas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('roteiro_id')->nullable()->constrained('roteiros')->nullOnDelete();
            $table->date('data');
            // Dia que o contrato prevê. O supervisor pode mover a visita (muda "data"), mas a
            // geração se guia por este campo, para não recriar nem apagar o que foi movido.
            $table->date('data_prevista')->nullable();
            $table->foreignId('agencia_id')->constrained('agencias')->cascadeOnDelete();
            $table->foreignId('pdv_id')->constrained('pdvs')->cascadeOnDelete();
            $table->foreignId('industria_id')->constrained('industrias')->cascadeOnDelete();
            $table->foreignId('contrato_id')->nullable()->constrained('contratos_visita')->nullOnDelete();
            $table->string('origem', 20); // contrato | demanda
            $table->string('status', 20)->default('previsto');
            $table->unsignedSmallInteger('ordem')->default(0);
            $table->timestamps();

            // A geração é idempotente: uma visita por contrato por dia previsto.
            $table->unique(['contrato_id', 'data_prevista']);
            $table->index(['agencia_id', 'data']);
            $table->index(['industria_id', 'data']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visitas');
        Schema::dropIfExists('roteiros');
    }
};
