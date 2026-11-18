<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_institucional', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->json('rascunho');
            $table->json('publicado')->nullable();
            $table->timestamp('publicado_em')->nullable();
            $table->timestamps();
        });
        Schema::create('site_contatos', function (Blueprint $table) {
            $table->id();
            $table->string('nome', 120);
            $table->string('email', 190);
            $table->string('empresa', 160);
            $table->string('perfil', 30);
            $table->text('mensagem');
            $table->string('situacao', 30)->default('novo')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_contatos');
        Schema::dropIfExists('site_institucional');
    }
};
