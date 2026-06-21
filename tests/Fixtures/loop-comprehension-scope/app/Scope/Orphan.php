<?php

namespace App\Scope;

/**
 * A built-but-unwired capability. Nothing in production references it — a TRUE orphan
 * the cyclomatic/coverage proxy discovery can never prioritize.
 */
final class Orphan
{
    public function run(int $x): int
    {
        return $x + 1;
    }
}
