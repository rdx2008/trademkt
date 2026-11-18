<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Cadastro do promotor na agência: CPF (guardado criptografado; o hash serve só para impedir
 * duplicidade), foto, cidade base, observações e o aceite do aviso de privacidade; convites de
 * primeiro acesso (link de uso único para o promotor criar a própria senha) e histórico de
 * alterações dos cadastros.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('cpf')->nullable();
            $table->string('cpf_hash', 64)->nullable()->unique();
            $table->string('foto')->nullable();
            $table->string('cidade_base', 80)->nullable();
            $table->string('uf_base', 2)->nullable();
            $table->text('observacoes')->nullable();
            $table->timestamp('aceite_privacidade_em')->nullable();
        });

        Schema::create('convites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('users')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expira_em');
            $table->timestamp('usado_em')->nullable();
            $table->unsignedBigInteger('criado_por')->nullable();
            $table->timestamps();
        });

        Schema::create('auditoria_usuarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('autor_id')->nullable();
            $table->string('acao', 40);
            $table->json('detalhes')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['usuario_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auditoria_usuarios');
        Schema::dropIfExists('convites');
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['cpf_hash']);
            $table->dropColumn(['cpf', 'cpf_hash', 'foto', 'cidade_base', 'uf_base', 'observacoes', 'aceite_privacidade_em']);
        });
    }
};
