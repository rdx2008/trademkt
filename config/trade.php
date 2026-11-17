<?php

return [
    'fotos_disk' => env('FOTOS_DISK', 'local'),
    // Fuso de quem não escolheu outro (cada empresa pode ter o seu: App\Support\Fuso)
    'fuso_padrao' => env('APP_TIMEZONE', 'America/Sao_Paulo'),
    'dominio_clientes' => env('TRADE_DOMINIO_CLIENTES', 'bra.net.br'),
    // Tipos de instalação vendidos
    'tipos_instalacao' => [
        'agencia' => 'Agência',
        'industria' => 'Indústria',
    ],

    // Esquema usado para montar a URL de cada cliente (https em produção)
    'esquema_url' => env('TRADE_ESQUEMA_URL', 'https'),

    // Versão da API consumida pelo app do promotor
    'api_versao' => '1',

    // Versões do app Android (APK por link, sem loja): abaixo da mínima o app pede para atualizar;
    // abaixo da atual só avisa. Suba a mínima só quando uma mudança da API quebrar o app antigo.
    'app' => [
        'versao_minima' => env('APP_VERSAO_MINIMA', '0.0.0'),
        'versao_atual' => env('APP_VERSAO_ATUAL', '0.0.0'),
    ],

    // Geocoding do endereço do PDV (só no cadastro, com cache)
    'geocoding' => [
        'google_key' => env('GOOGLE_MAPS_API_KEY'),
        // Sem chave do Google, usa o OpenStreetMap (gratuito, sem cadastro).
        'osm' => env('GEOCODING_OSM', true),
        'cache_dias' => 90,
    ],

    // Importação de PDVs por planilha
    'importacao_pdvs' => [
        'max_kb' => 10240,
        'max_linhas' => 5000,
    ],

    // Importação do catálogo de SKUs (planilha ou PDF)
    'importacao_skus' => [
        'max_kb' => 61440,
        'max_linhas' => 5000,
    ],

    // Super admin criado pelo "php artisan db:seed" (banco central)
    'super_admin' => [
        'nome' => env('SUPER_ADMIN_NOME', 'Super admin'),
        'email' => env('SUPER_ADMIN_EMAIL'),
        'senha' => env('SUPER_ADMIN_SENHA'),
    ],
];
