<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Município (código IBGE) escolhido na lista e raio de atuação em km a partir do centro dele.
        Schema::table('users', function (Blueprint $table) {
            $table->string('municipio_ibge', 7)->nullable()->index()->after('uf_base');
            $table->unsignedSmallInteger('raio_km')->nullable()->after('municipio_ibge');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['municipio_ibge', 'raio_km']));
    }
};
