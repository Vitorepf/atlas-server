<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Test bootstrap — hard database pin
|--------------------------------------------------------------------------
|
| The live Postgres was wiped twice by the test suite. Both times the guard
| lived in the wrong layer:
|
|   - `phpunit.xml` <env force="true"> only writes putenv() + $_ENV, but
|     Laravel's Env repository reads $_SERVER FIRST, and `docker compose`
|     exports DB_* into $_SERVER. The pin lost.
|   - `TestCase::setUp()` cannot save us either: PHPUnit sets
|     $hasMetRequirements BEFORE setUp runs, so tearDown (and RefreshDatabase
|     rollback) still fires against whatever connection was resolved.
|
| So the pin has to happen here — before the autoloader, before the app, and
| in the superglobal that actually wins. Anything that wants the live database
| must opt in explicitly with ATLAS_ALLOW_LIVE_DB_TESTS=1.
|
*/

$allowLiveDatabase = ($_SERVER['ATLAS_ALLOW_LIVE_DB_TESTS'] ?? getenv('ATLAS_ALLOW_LIVE_DB_TESTS')) === '1';

if (! $allowLiveDatabase) {
    $pins = [
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => ':memory:',
        'DB_URL' => '',
        'DATABASE_URL' => '',
    ];

    foreach ($pins as $key => $value) {
        $_SERVER[$key] = $value;
        $_ENV[$key] = $value;
        putenv($key.'='.$value);
    }

    // Host/port/credentials would silently redirect a pgsql connection that some
    // config path resolves anyway; strip them so a leak fails loud, not quietly.
    foreach (['DB_HOST', 'DB_PORT', 'DB_USERNAME', 'DB_PASSWORD', 'DB_SOCKET'] as $key) {
        unset($_SERVER[$key], $_ENV[$key]);
        putenv($key);
    }
}

require __DIR__.'/../vendor/autoload.php';
