<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\DurableExecution;

final class DurableExecutionFieldReader
{
    /**
     * @param  array<string,mixed>  $source
     */
    public static function stringOrNull(array $source, string $key): ?string
    {
        $value = $source[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
