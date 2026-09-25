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

    // Geocoding do endereço do PDV (só no cadastro, com cache)
    'geocoding' => [
        'google_key' => env('GOOGLE_MAPS_API_KEY'),
        'cache_dias' => 90,
    ],

    // Importação de PDVs por planilha
    'importacao_pdvs' => [
        'max_kb' => 10240,
        'max_linhas' => 5000,
    ],

    // Super admin criado pelo "php artisan db:seed" (banco central)
    'super_admin' => [
        'nome' => env('SUPER_ADMIN_NOME', 'Super admin'),
        'email' => env('SUPER_ADMIN_EMAIL'),
        'senha' => env('SUPER_ADMIN_SENHA'),
    ],
];
