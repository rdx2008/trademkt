<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parcerias', function (Blueprint $table) {
            $table->json('regioes_interesse')->nullable();
            $table->json('regioes_anteriores')->nullable();
        });
        DB::table('parcerias')->whereNotNull('uf')->orderBy('id')->chunkById(200, function ($parcerias) {
            foreach ($parcerias as $parceria) {
                DB::table('parcerias')->where('id', $parceria->id)->update(['regioes_interesse' => json_encode([['uf' => $parceria->uf, 'cidade' => $parceria->cidade]], JSON_UNESCAPED_UNICODE)]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('parcerias', fn (Blueprint $table) => $table->dropColumn(['regioes_interesse', 'regioes_anteriores']));
    }
};
