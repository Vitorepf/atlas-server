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
