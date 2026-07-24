<?php

use Illuminate\Support\Str;
use Pdo\Mysql;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for database operations. This is
    | the connection which will be utilized unless another connection
    | is explicitly specified when you execute a query / statement.
    |
    */

    // PISO PÉTREO: um boot SEM env (worktree/checkout sem .env — os dois vetores
    // do wiper de 02/07) nunca pode herdar o pgsql VIVO por default. O repo vivo
    // declara DB_CONNECTION=pgsql explicitamente no .env; qualquer ambiente
    // anônimo cai em sqlite local e não alcança dados de produção.
    'default' => env('DB_CONNECTION', 'sqlite'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Below are all of the database connections defined for your application.
    | An example configuration is provided for each database system which
    | is supported by Laravel. You're free to add / remove connections.
    |
    */

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'busy_timeout' => null,
            'journal_mode' => null,
            'synchronous' => null,
            'transaction_mode' => 'DEFERRED',
        ],

        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                (PHP_VERSION_ID >= 80500 ? Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA) => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'mariadb' => [
            'driver' => 'mariadb',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                (PHP_VERSION_ID >= 80500 ? Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA) => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],

        // P2a.1 real-PostgreSQL contract. These connections are inert unless the
        // guarded ATLAS_TEST_PG_* environment is supplied by the focused test run.
        'atlas_p2_pg_setup' => [
            'driver' => 'pgsql',
            'host' => env('ATLAS_TEST_PG_HOST', '127.0.0.1'),
            'port' => env('ATLAS_TEST_PG_PORT', '5432'),
            'database' => env('ATLAS_TEST_PG_DATABASE', 'atlas_test_missing'),
            'username' => env('ATLAS_TEST_PG_USERNAME', ''),
            'password' => env('ATLAS_TEST_PG_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('ATLAS_TEST_PG_SSLMODE', 'prefer'),
            'application_name' => 'atlas_p2a1_setup',
        ],

        'atlas_p2_pg_runtime' => [
            'driver' => 'pgsql',
            'host' => env('ATLAS_TEST_PG_HOST', '127.0.0.1'),
            'port' => env('ATLAS_TEST_PG_PORT', '5432'),
            'database' => env('ATLAS_TEST_PG_DATABASE', 'atlas_test_missing'),
            'username' => env('ATLAS_TEST_PG_RUNTIME_USERNAME', 'atlas_p2a1_runtime'),
            'password' => env('ATLAS_TEST_PG_RUNTIME_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('ATLAS_TEST_PG_SSLMODE', 'prefer'),
            'application_name' => 'atlas_p2a1_runtime',
        ],

        'atlas_p2_pg_verifier' => [
            'driver' => 'pgsql',
            'host' => env('ATLAS_TEST_PG_HOST', '127.0.0.1'),
            'port' => env('ATLAS_TEST_PG_PORT', '5432'),
            'database' => env('ATLAS_TEST_PG_DATABASE', 'atlas_test_missing'),
            'username' => env('ATLAS_TEST_PG_VERIFIER_USERNAME', 'atlas_p2a1_verifier'),
            'password' => env('ATLAS_TEST_PG_VERIFIER_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('ATLAS_TEST_PG_SSLMODE', 'prefer'),
            'application_name' => 'atlas_p2a1_verifier',
        ],

        // P4 REAL_OPERATION durable identities. Prefer full URLs
        // (ATLAS_P4_PG_PRODUCER_URL / ATLAS_P4_PG_VERIFIER_URL). Component envs
        // are fallbacks for local docker provisioners.
        'atlas_p4_pg_setup' => [
            'driver' => 'pgsql',
            'url' => env('ATLAS_P4_PG_SETUP_URL'),
            'host' => env('ATLAS_P4_PG_HOST', '127.0.0.1'),
            'port' => env('ATLAS_P4_PG_PORT', '5432'),
            'database' => env('ATLAS_P4_PG_DATABASE', 'atlas_p4_missing'),
            'username' => env('ATLAS_P4_PG_SETUP_USERNAME', 'postgres'),
            'password' => env('ATLAS_P4_PG_SETUP_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('ATLAS_P4_PG_SSLMODE', 'prefer'),
            'application_name' => 'atlas_p4_setup',
        ],

        'atlas_p4_pg_producer' => [
            'driver' => 'pgsql',
            'url' => env('ATLAS_P4_PG_PRODUCER_URL'),
            'host' => env('ATLAS_P4_PG_HOST', '127.0.0.1'),
            'port' => env('ATLAS_P4_PG_PORT', '5432'),
            'database' => env('ATLAS_P4_PG_DATABASE', 'atlas_p4_missing'),
            'username' => env('ATLAS_P4_PG_PRODUCER_USERNAME', 'atlas_p4_producer'),
            'password' => env('ATLAS_P4_PG_PRODUCER_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('ATLAS_P4_PG_SSLMODE', 'prefer'),
            'application_name' => 'atlas_p4_producer',
        ],

        'atlas_p4_pg_verifier' => [
            'driver' => 'pgsql',
            'url' => env('ATLAS_P4_PG_VERIFIER_URL'),
            'host' => env('ATLAS_P4_PG_HOST', '127.0.0.1'),
            'port' => env('ATLAS_P4_PG_PORT', '5432'),
            'database' => env('ATLAS_P4_PG_DATABASE', 'atlas_p4_missing'),
            'username' => env('ATLAS_P4_PG_VERIFIER_USERNAME', 'atlas_p4_verifier'),
            'password' => env('ATLAS_P4_PG_VERIFIER_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('ATLAS_P4_PG_SSLMODE', 'prefer'),
            'application_name' => 'atlas_p4_verifier',
            'options' => extension_loaded('pdo_pgsql') ? [
                // Prefer read-only session when driver supports it.
            ] : [],
        ],

        // Production ledger identities are deliberately separate from the
        // application's broad database connection. They are inert until the
        // deployment opts into role-enforced evidence I/O below.
        'atlas_ledger_runtime' => [
            'driver' => 'pgsql',
            'url' => env('ATLAS_LEDGER_RUNTIME_URL'),
            'host' => env('ATLAS_LEDGER_RUNTIME_HOST', env('DB_HOST', '127.0.0.1')),
            'port' => env('ATLAS_LEDGER_RUNTIME_PORT', env('DB_PORT', '5432')),
            'database' => env('ATLAS_LEDGER_RUNTIME_DATABASE', env('DB_DATABASE', 'laravel')),
            'username' => env('ATLAS_LEDGER_RUNTIME_USERNAME', ''),
            'password' => env('ATLAS_LEDGER_RUNTIME_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('ATLAS_LEDGER_RUNTIME_SSLMODE', env('DB_SSLMODE', 'prefer')),
            'application_name' => 'atlas_ledger_runtime',
        ],

        'atlas_ledger_verifier' => [
            'driver' => 'pgsql',
            'url' => env('ATLAS_LEDGER_VERIFIER_URL'),
            'host' => env('ATLAS_LEDGER_VERIFIER_HOST', env('DB_HOST', '127.0.0.1')),
            'port' => env('ATLAS_LEDGER_VERIFIER_PORT', env('DB_PORT', '5432')),
            'database' => env('ATLAS_LEDGER_VERIFIER_DATABASE', env('DB_DATABASE', 'laravel')),
            'username' => env('ATLAS_LEDGER_VERIFIER_USERNAME', ''),
            'password' => env('ATLAS_LEDGER_VERIFIER_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('ATLAS_LEDGER_VERIFIER_SSLMODE', env('DB_SSLMODE', 'prefer')),
            'application_name' => 'atlas_ledger_verifier',
        ],

        // Nivor / Blackink tracker — READ-ONLY (additive; never write to this connection).
        'nivor' => [
            'driver' => 'pgsql',
            'host' => env('NIVOR_DB_HOST', '127.0.0.1'),
            'port' => env('NIVOR_DB_PORT', '5432'),
            'database' => env('NIVOR_DB_DATABASE', 'blackink'),
            'username' => env('NIVOR_DB_USERNAME', ''),
            'password' => env('NIVOR_DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('NIVOR_DB_SSLMODE', 'prefer'),
        ],

        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', '1433'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            // 'encrypt' => env('DB_ENCRYPT', 'yes'),
            // 'trust_server_certificate' => env('DB_TRUST_SERVER_CERTIFICATE', 'false'),
        ],

    ],

    // When enabled, evidence writes and proof verification must use these
    // PostgreSQL identities. An incomplete role configuration is a hard fail,
    // never a fallback to the application's default connection.
    'ledger_roles' => [
        'enforced' => env('ATLAS_LEDGER_ROLE_ENFORCED', false),
        'runtime_connection' => env('ATLAS_LEDGER_RUNTIME_CONNECTION', 'atlas_ledger_runtime'),
        'verifier_connection' => env('ATLAS_LEDGER_VERIFIER_CONNECTION', 'atlas_ledger_verifier'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run on the database.
    |
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as Memcached. You may define your connection settings here.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-database-'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

    ],

];
