<?php

namespace App\Services\Ai\Runtime;

final class TestCommandInput
{
    public const DEFAULT_MEMORY_LIMIT = '1024M';

    public function memoryLimit(mixed $value = null): string
    {
        $configured = trim((string) ($value ?? config('atlas.ai.test_memory_limit', self::DEFAULT_MEMORY_LIMIT)));

        return preg_match('/^\d+[KMG]?$/i', $configured) === 1
            ? $configured
            : self::DEFAULT_MEMORY_LIMIT;
    }
}
