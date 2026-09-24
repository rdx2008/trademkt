<?php

return [
    // Tipos de instalação vendidos
    'tipos_instalacao' => [
        'agencia' => 'Agência',
        'industria' => 'Indústria',
    ],

    // Esquema usado para montar a URL de cada cliente (https em produção)
    'esquema_url' => env('TRADE_ESQUEMA_URL', 'https'),

    // Versão da API consumida pelo app do promotor
    'api_versao' => '1',

    // Super admin criado pelo "php artisan db:seed" (banco central)
    'super_admin' => [
        'nome' => env('SUPER_ADMIN_NOME', 'Super admin'),
        'email' => env('SUPER_ADMIN_EMAIL'),
        'senha' => env('SUPER_ADMIN_SENHA'),
    ],
];
