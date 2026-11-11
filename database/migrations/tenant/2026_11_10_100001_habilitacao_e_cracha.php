<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Indústrias para as quais o promotor está habilitado. Sem linhas = todas as que a agência atende.
        // `industria_id` é o id da instalação da indústria (sem FK: mora em outro banco).
        Schema::create('promotor_industrias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('users')->cascadeOnDelete();
            $table->string('industria_id', 64);
            $table->timestamps();
            $table->unique(['usuario_id', 'industria_id']);
        });

        // Código público do crachá (QR): quem lê vê foto, primeiro nome e situação, nunca o CPF.
        Schema::table('users', function (Blueprint $table) {
            $table->string('cracha_token', 40)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['cracha_token']);
            $table->dropColumn('cracha_token');
        });
        Schema::dropIfExists('promotor_industrias');
    }
};
