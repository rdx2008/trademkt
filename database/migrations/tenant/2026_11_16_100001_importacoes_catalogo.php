<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Trilha das importações de catálogo gravadas (fase 9): quem importou, pela indústria ou por uma
        // agência vinculada. `agencia_id` é a linha da agência neste banco; `usuario_id` é do banco de origem.
        Schema::create('importacoes_catalogo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('industria_id')->constrained('industrias')->cascadeOnDelete();
            $table->unsignedBigInteger('agencia_id')->nullable();
            $table->unsignedBigInteger('usuario_id')->nullable();
            $table->string('usuario_nome', 120)->nullable();
            $table->string('fonte', 20);
            $table->string('arquivo', 200)->nullable();
            $table->unsignedInteger('criados')->default(0);
            $table->unsignedInteger('atualizados')->default(0);
            $table->unsignedInteger('ignorados')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->index(['industria_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('importacoes_catalogo');
    }
};
