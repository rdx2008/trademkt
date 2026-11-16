<?php

return [
    // Caminho do login do super admin (sem barra inicial). Troque em produção.
    'acesso' => env('INSTITUCIONAL_ACESSO', 'conexao-7f4c92'),

    // Endereço da landing page (ex.: bra.net.br). Vazio = a landing e o painel respondem em todo
    // domínio central. Preenchido = a landing só nele e o painel só nos outros domínios centrais.
    'dominio_site' => env('INSTITUCIONAL_DOMINIO_SITE') ?: null,
];
