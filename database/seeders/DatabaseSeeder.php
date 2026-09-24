<?php

namespace Database\Seeders;

use App\Models\Admin;
use Illuminate\Database\Seeder;

/**
 * Banco central. Cria o super admin a partir do .env, se informado.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('trade.super_admin.email');
        $senha = config('trade.super_admin.senha');

        if ($email && $senha && ! Admin::where('email', strtolower($email))->exists()) {
            Admin::create([
                'nome' => config('trade.super_admin.nome'),
                'email' => strtolower($email),
                'password' => $senha,
            ]);
        }
    }
}
