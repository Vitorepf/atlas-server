<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Test-infra only (does NOT touch production runtime): several Feature
        // tests exercise HTTP controllers that call set_time_limit() to bound a
        // single web request. In the long-lived CLI test process that limit
        // persists across tests, and a later, slower test inherits it — the
        // Forge Rivals real-git battery hit an inherited 360s max_execution_time
        // and fataled the whole run near the end with "Premature end of PHP
        // process". Reset to the CLI default (unlimited) at the start of every
        // test so no test is poisoned by a prior test's request-scoped clamp and
        // the full suite can run to completion.
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
    }
}
