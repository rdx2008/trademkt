<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Login único do app: o e-mail do promotor em cada agência, para descobrir onde ele está
        // sem pedir o código da agência. A senha continua só no banco de cada agência.
        Schema::table('promotor_agencias', function (Blueprint $table) {
            $table->string('email', 190)->nullable()->index()->after('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::table('promotor_agencias', fn (Blueprint $table) => $table->dropColumn('email'));
    }
};
