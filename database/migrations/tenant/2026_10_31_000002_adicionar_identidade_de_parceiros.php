<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencias', fn (Blueprint $table) => $table->unsignedBigInteger('agencia_rede_id')->nullable()->unique());
        Schema::table('users', function (Blueprint $table) {
            $table->string('parceiro_origem_tenant')->nullable();
            $table->unsignedBigInteger('parceiro_origem_usuario_id')->nullable();
            $table->unique(['parceiro_origem_tenant', 'parceiro_origem_usuario_id'], 'usuario_parceiro_unico');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('usuario_parceiro_unico');
            $table->dropColumn(['parceiro_origem_tenant', 'parceiro_origem_usuario_id']);
        });
        Schema::table('agencias', function (Blueprint $table) {
            $table->dropUnique(['agencia_rede_id']);
            $table->dropColumn('agencia_rede_id');
        });
    }
};
