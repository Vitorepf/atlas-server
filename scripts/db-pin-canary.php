<?php

declare(strict_types=1);

/*
| Canary for the test-suite database pin (tests/bootstrap.php).
|
| Reproduces the exact wipe mechanism: $_SERVER carries the live pgsql
| credentials (docker compose exports them) while phpunit.xml's
| <env force="true"> has only written putenv() + $_ENV. Laravel's Env
| repository reads $_SERVER first, so without the pin the suite resolves the
| LIVE database and RefreshDatabase drops it.
|
| Run standalone — never through phpunit:
|   php scripts/db-pin-canary.php
|
| Exit 0 = pin holds. Exit 1 = the suite would hit the live database.
*/

$root = dirname(__DIR__);

$boot = static function (bool $withPin) use ($root): string {
    $script = <<<'PHP'
        $_SERVER['DB_CONNECTION'] = 'pgsql';
        $_SERVER['DB_DATABASE']   = 'atlas';
        $_SERVER['DB_HOST']       = '127.0.0.1';
        $_SERVER['DB_PORT']       = '5433';
        // what phpunit.xml <env force="true"> actually writes:
        putenv('DB_CONNECTION=sqlite');
        $_ENV['DB_CONNECTION'] = 'sqlite';
        require __DIR__ . '/%s';
        $app = require __DIR__ . '/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $default = config('database.default');
        echo $default . '|' . config('database.connections.' . $default . '.database');
        PHP;

    $entry = $withPin ? 'tests/bootstrap.php' : 'vendor/autoload.php';
    $code = sprintf($script, $entry);

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open([PHP_BINARY, '-r', $code], $descriptors, $pipes, $root);
    if (! is_resource($process)) {
        fwrite(STDERR, "canary: could not spawn php\n");
        exit(1);
    }
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    return trim((string) $out);
};

$pinned = $boot(true);
$unpinned = $boot(false);

echo "with tests/bootstrap.php : {$pinned}\n";
echo "with vendor/autoload.php : {$unpinned}\n";

if ($pinned !== 'sqlite|:memory:') {
    fwrite(STDERR, "FAIL: pin did not hold — the suite would reach {$pinned}\n");
    exit(1);
}

if ($unpinned === 'sqlite|:memory:') {
    fwrite(STDERR, "FAIL: canary is vacuous — the unpinned path already resolves sqlite,\n"
        ."so this check would pass even if tests/bootstrap.php were deleted.\n");
    exit(1);
}

echo "PASS: pin holds against \$_SERVER, and the canary is non-vacuous.\n";
exit(0);
