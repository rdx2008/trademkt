<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Banco de cada cliente: indústrias, agências e usuários.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('industrias', function (Blueprint $table) {
            $table->id();
            $table->string('nome');
            $table->string('cnpj', 18)->nullable()->unique();
            $table->string('segmento')->nullable();
            $table->boolean('ativo')->default(true);
            $table->timestamps();
        });

        Schema::create('agencias', function (Blueprint $table) {
            $table->id();
            $table->string('nome');
            $table->string('cnpj', 18)->nullable()->unique();
            $table->boolean('ativo')->default(true);
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('nome');
            $table->string('email')->unique();
            $table->string('telefone', 20)->nullable();
            $table->string('password');
            $table->string('perfil', 30)->index();
            $table->foreignId('industria_id')->nullable()->constrained('industrias')->nullOnDelete();
            $table->foreignId('agencia_id')->nullable()->constrained('agencias')->nullOnDelete();
            $table->string('tipo_vinculo', 20)->nullable();
            // Identidade única do promotor no painel central (etapa futura da rede de freelancers)
            $table->string('promotor_global_id', 40)->nullable()->index();
            $table->boolean('ativo')->default(true);
            $table->timestamp('ultimo_acesso_em')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
        Schema::dropIfExists('agencias');
        Schema::dropIfExists('industrias');
    }
};
