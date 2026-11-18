<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Promotor novo nasce com a habilitação restrita e vazia: só recebe visitas das indústrias que o
        // admin marcar. Os já cadastrados continuam como estavam (false = todas as indústrias atendidas).
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('habilitacao_restrita')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('habilitacao_restrita');
        });
    }
};
