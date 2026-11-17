<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Município escolhido (código IBGE) e raio de atuação a partir do centro dele.
        Schema::table('promotores_globais', function (Blueprint $table) {
            $table->string('municipio_ibge', 7)->nullable()->index()->after('uf_base');
        });

        // Em quais agências o promotor está cadastrado e se está ativo em cada uma. Permite ao
        // super admin contar quantas pessoas únicas há na rede, por região, sem abrir o banco de cada agência.
        Schema::create('promotor_agencias', function (Blueprint $table) {
            $table->id();
            $table->string('promotor_global_codigo', 40);
            $table->string('tenant_id');
            $table->boolean('ativo')->default(true);
            $table->timestamps();
            $table->unique(['promotor_global_codigo', 'tenant_id']);
            $table->index(['tenant_id', 'ativo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotor_agencias');
        Schema::table('promotores_globais', fn (Blueprint $table) => $table->dropColumn('municipio_ibge'));
    }
};
