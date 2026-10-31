<?php

use App\Services\Rede\PerfilAgencia;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parcerias', function (Blueprint $table) {
            $table->string('uf', 2)->nullable();
            $table->string('cidade', 100)->nullable();
            $table->string('regiao_anterior', 200)->nullable();
            $table->timestamp('regiao_atualizada_em')->nullable();
            $table->unsignedBigInteger('regiao_alterada_por')->nullable();
        });
        // Só normaliza o histórico quando o valor já é uma UF inequívoca.
        DB::table('parcerias')->whereIn('regiao', PerfilAgencia::UFS)->update(['uf' => DB::raw('regiao')]);
    }

    public function down(): void
    {
        Schema::table('parcerias', fn (Blueprint $table) => $table->dropColumn(['uf', 'cidade', 'regiao_anterior', 'regiao_atualizada_em', 'regiao_alterada_por']));
    }
};
