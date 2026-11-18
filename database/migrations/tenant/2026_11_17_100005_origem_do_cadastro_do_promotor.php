<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // painel (admin cadastrou) | importacao | link (o próprio promotor, pelo link da agência)
        Schema::table('users', function (Blueprint $table) {
            $table->string('origem_cadastro', 12)->default('painel')->after('tipo_vinculo');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('origem_cadastro'));
    }
};
