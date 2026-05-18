<?php

namespace App\Services\Ai\ProgrammingRuntime;

/**
 * Tiny config seam so tests can override `atlas_ai.tool_runtime.strict_mode`
 * without mutating the real container config.
 */
interface ConfigReader
{
    public function get(string $key, mixed $default = null): mixed;
}
