<?php

declare(strict_types=1);

use App\Models\Tenant;
use Stancl\Tenancy\Bootstrappers\CacheTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper;
use Stancl\Tenancy\Database\Models\Domain;
use Stancl\Tenancy\TenantDatabaseManagers\MySQLDatabaseManager;
use Stancl\Tenancy\TenantDatabaseManagers\PostgreSQLDatabaseManager;
use Stancl\Tenancy\TenantDatabaseManagers\SQLiteDatabaseManager;

/*
 * Multi-cliente por domínio (stancl/tenancy v3).
 * Cada cliente (agência ou indústria) tem: banco próprio, pasta própria em
 * storage/clientes/<id> e um ou mais domínios. O código é um só.
 */
return [
    'tenant_model' => Tenant::class,

    // O id do cliente é o slug informado no provisionamento (ex.: "gaboardi").
    'id_generator' => null,

    'domain_model' => Domain::class,

    // Domínios do painel central (fornecedor). Separe por vírgula no .env.
    'central_domains' => array_filter(array_map('trim', explode(',', env('CENTRAL_DOMAINS', 'localhost,127.0.0.1')))),

    'bootstrappers' => [
        DatabaseTenancyBootstrapper::class,
        CacheTenancyBootstrapper::class,
        FilesystemTenancyBootstrapper::class,
        QueueTenancyBootstrapper::class,
    ],

    'database' => [
        'central_connection' => env('DB_CONNECTION', 'mysql'),

        // null = o banco do cliente usa a mesma conexão central, trocando só o nome do banco.
        'template_tenant_connection' => null,

        // Banco do cliente "gaboardi" => cli_gaboardi
        'prefix' => env('TENANT_DB_PREFIX', 'cli_'),
        'suffix' => '',

        'managers' => [
            'sqlite' => SQLiteDatabaseManager::class,
            'mysql' => MySQLDatabaseManager::class,
            'mariadb' => MySQLDatabaseManager::class,
            'pgsql' => PostgreSQLDatabaseManager::class,
        ],
    ],

    // Chaves de cache ganham a tag do cliente (exige store com tags: redis ou array).
    'cache' => [
        'tag_base' => 'cliente',
    ],

    // storage_path() dentro de um cliente vira storage/clientes/<id>
    'filesystem' => [
        'suffix_base' => 'clientes/',
        'disks' => [
            'local',
            'public',
        ],
        'root_override' => [
            'local' => '%storage_path%/app/',
            'public' => '%storage_path%/app/public/',
        ],
        'suffix_storage_path' => true,
        'asset_helper_tenancy' => false,
    ],

    'redis' => [
        'prefix_base' => 'cliente',
        'prefixed_connections' => [],
    ],

    'features' => [],

    'routes' => true,

    'migration_parameters' => [
        '--force' => true,
        '--path' => [database_path('migrations/tenant')],
        '--realpath' => true,
    ],

    'seeder_parameters' => [
        '--class' => 'Database\\Seeders\\TenantSeeder',
    ],
];
