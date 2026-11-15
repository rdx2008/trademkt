<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Avaliação de foto (1 a 5 estrelas e tags) pela gestão da indústria ou da agência. Uma por avaliador:
        // `agencia_id` é a linha da agência neste banco (nulo = usuário da própria indústria).
        Schema::create('foto_avaliacoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('foto_id')->constrained('visita_fotos')->cascadeOnDelete();
            $table->unsignedBigInteger('agencia_id')->nullable();
            $table->unsignedBigInteger('usuario_id');
            $table->string('usuario_nome', 120);
            $table->unsignedTinyInteger('nota')->nullable();
            $table->json('tags')->nullable();
            $table->timestamps();
            $table->unique(['foto_id', 'agencia_id', 'usuario_id'], 'foto_avaliacao_unica');
            $table->index('nota');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foto_avaliacoes');
    }
};
