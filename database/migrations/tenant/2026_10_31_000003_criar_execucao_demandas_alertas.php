<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitas', function (Blueprint $t) {
            $t->string('tipo_conclusao', 20)->nullable();
            $t->uuid('finalizacao_uuid')->nullable();
            $t->string('finalizacao_hash', 64)->nullable();
            $t->dateTime('concluida_em')->nullable()->index();
            $t->dateTime('recebida_em')->nullable();
            $t->unsignedInteger('duracao_segundos')->nullable();
            $t->text('observacao')->nullable();
        });
        Schema::create('visita_fotos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('visita_id')->constrained('visitas')->cascadeOnDelete();
            $t->uuid('uuid');
            $t->string('tipo', 20);
            $t->string('caminho');
            $t->string('disco', 30)->default('local');
            $t->string('hash_origem', 64);
            $t->json('metadados');
            $t->timestamps();
            $t->unique(['visita_id', 'uuid']);
        });
        Schema::create('visita_skus', function (Blueprint $t) {
            $t->id();
            $t->foreignId('visita_id')->constrained('visitas')->cascadeOnDelete();
            $t->foreignId('sku_id')->constrained('skus')->restrictOnDelete();
            $t->string('situacao_estoque', 30);
            $t->unique(['visita_id', 'sku_id']);
        });
        Schema::create('demandas', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->string('payload_hash', 64);
            $t->foreignId('solicitante_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('contrato_id')->constrained('contratos_visita')->restrictOnDelete();
            $t->foreignId('pdv_id')->constrained('pdvs')->restrictOnDelete();
            $t->foreignId('industria_id')->constrained('industrias')->restrictOnDelete();
            $t->foreignId('agencia_id')->constrained('agencias')->restrictOnDelete();
            $t->string('tipo', 20);
            $t->string('motivo', 30);
            $t->text('observacao')->nullable();
            $t->json('skus')->nullable();
            $t->string('foto')->nullable();
            $t->string('foto_disco', 30)->default('local');
            $t->string('status', 20)->default('aberta')->index();
            $t->dateTime('prazo_em')->index();
            $t->dateTime('atendida_em')->nullable();
            $t->dateTime('atraso_notificado_em')->nullable();
            $t->foreignId('liberada_por')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('visita_id')->nullable()->unique()->constrained('visitas')->restrictOnDelete();
            $t->text('resposta')->nullable();
            $t->timestamps();
        });
        Schema::create('alertas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('usuario_id')->constrained('users')->cascadeOnDelete();
            $t->string('chave', 150);
            $t->string('tipo', 40);
            $t->string('titulo');
            $t->text('mensagem');
            $t->foreignId('visita_id')->nullable()->constrained('visitas')->cascadeOnDelete();
            $t->foreignId('demanda_id')->nullable()->constrained('demandas')->cascadeOnDelete();
            $t->dateTime('lida_em')->nullable();
            $t->timestamps();
            $t->unique(['usuario_id', 'chave']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alertas');
        Schema::dropIfExists('demandas');
        Schema::dropIfExists('visita_skus');
        Schema::dropIfExists('visita_fotos');
        Schema::table('visitas', fn (Blueprint $t) => $t->dropColumn(['tipo_conclusao', 'finalizacao_uuid', 'finalizacao_hash', 'concluida_em', 'recebida_em', 'duracao_segundos', 'observacao']));
    }
};
