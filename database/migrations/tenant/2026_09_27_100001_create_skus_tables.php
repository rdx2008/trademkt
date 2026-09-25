<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Fase 2.2: catálogo de SKUs por indústria, com as embalagens de cada produto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('industria_id')->constrained('industrias')->cascadeOnDelete();
            $table->string('codigo', 40);
            $table->string('descricao', 200);
            $table->string('categoria', 80)->nullable()->index();
            $table->string('foto')->nullable(); // caminho no disco do cliente
            $table->boolean('ativo')->default(true);
            $table->timestamps();

            $table->unique(['industria_id', 'codigo']);
        });

        Schema::create('sku_embalagens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sku_id')->constrained('skus')->cascadeOnDelete();
            $table->string('tipo', 20); // unidade | reembalagem | caixa_master
            $table->unsignedInteger('quantidade')->default(1);
            $table->string('gtin', 14)->nullable()->index();
            // null = sem GTIN; false = dígito verificador errado (marcado, não bloqueado)
            $table->boolean('gtin_valido')->nullable();
            $table->string('codigo', 40)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sku_embalagens');
        Schema::dropIfExists('skus');
    }
};
