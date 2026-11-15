<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Central de atendimento: chamados da empresa ao fornecedor, com a conversa em mensagens.
        Schema::create('chamados', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->string('assunto', 150);
            $table->string('categoria', 20)->default('duvida');
            // aberto | respondido | fechado
            $table->string('situacao', 12)->default('aberto');
            $table->string('aberto_por_nome', 120);
            $table->string('aberto_por_email', 190)->nullable();
            $table->timestamp('ultima_mensagem_em')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'situacao']);
        });

        Schema::create('chamado_mensagens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chamado_id')->constrained('chamados')->cascadeOnDelete();
            // empresa (quem abriu) ou fornecedor (super admin)
            $table->string('autor', 12);
            $table->string('autor_nome', 120);
            $table->text('texto');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chamado_mensagens');
        Schema::dropIfExists('chamados');
    }
};
