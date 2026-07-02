<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * The pristine memory_limit configured for the test process (phpunit.xml),
     * captured once per worker before any test body can clamp it.
     */
    private static ?string $pristineMemoryLimit = null;

    protected function setUp(): void
    {
        self::$pristineMemoryLimit ??= (string) ini_get('memory_limit');

        parent::setUp();

        // Live-DB kill-switch (incidente 02/07/2026): uma suite lançada de um
        // shell com DB_* exportado (`set -a; source .env`) vaza $_SERVER por
        // cima dos pins do phpunit.xml e roda migration->down()/RefreshDatabase
        // contra o Postgres de PRODUÇÃO — foi assim que o registro canônico de
        // memória foi destruído. Nenhum teste roda fora do sqlite :memory: sem
        // opt-in explícito (ATLAS_ALLOW_LIVE_DB_TESTS=1, usado só pelos testes
        // pgsql deliberados e guardados).
        if (config('database.default') !== 'sqlite' && getenv('ATLAS_ALLOW_LIVE_DB_TESTS') !== '1') {
            throw new \RuntimeException(
                'Test suite is pointed at a non-sqlite database ('
                .config('database.default')
                .'). Refusing to run: this wipes production. Export ATLAS_ALLOW_LIVE_DB_TESTS=1 only for the guarded pgsql-only tests.'
            );
        }

        // Test-infra only (does NOT touch production runtime): several Atlas
        // HTTP controllers, console commands and services call set_time_limit()
        // / ini_set('memory_limit', ...) to bound a single web request or CLI
        // run (e.g. max_execution_time -> 360s, memory_limit -> 512M). Under
        // `php artisan test` those run IN-PROCESS via Artisan::call() and HTTP
        // test requests, so the clamp persists across tests in the long-lived
        // (and paratest-reused) worker process. A later, heavier test then
        // inherits the lowered ceiling and fatals — which surfaced as two
        // "Premature end of PHP process" crashes near the end of the full suite
        // (the Forge Rivals real-git battery at an inherited 360s limit, and the
        // autonomous-holding enterprise flow OOMing at an inherited 512M). Reset
        // both ceilings to the suite baseline at the start of every test so no
        // test is poisoned by a prior test's request/CLI-scoped clamp and the
        // full suite can run to completion.
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        if (self::$pristineMemoryLimit !== null && self::$pristineMemoryLimit !== '') {
            @ini_set('memory_limit', self::$pristineMemoryLimit);
        }
    }
}
