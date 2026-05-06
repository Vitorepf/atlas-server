<?php

namespace App\Services\Ai\Provider;

final class ProviderProjectionInput
{
    public const DEFAULT_MAX_LINES = 80;

    public const MIN_MAX_LINES = 20;

    public const MAX_MAX_LINES = 240;

    public const DEFAULT_MEMORY_LIMIT = 18;

    public const MAX_MEMORY_LIMIT = 80;

    public const DEFAULT_MEMORY_CHARS = 220;

    public const MIN_MEMORY_CHARS = 80;

    public const MAX_MEMORY_CHARS = 1200;

    public function maxLines(mixed $value = null): int
    {
        return $this->limit(
            $value ?? config('atlas.ai.provider_projection_max_lines', self::DEFAULT_MAX_LINES),
            self::DEFAULT_MAX_LINES,
            self::MIN_MAX_LINES,
            self::MAX_MAX_LINES,
        );
    }

    public function memoryLimit(mixed $value = null): int
    {
        return $this->limit(
            $value ?? config('atlas.ai.provider_projection_memory_limit', self::DEFAULT_MEMORY_LIMIT),
            self::DEFAULT_MEMORY_LIMIT,
            1,
            self::MAX_MEMORY_LIMIT,
        );
    }

    public function memoryChars(mixed $value = null): int
    {
        return $this->limit(
            $value ?? config('atlas.ai.provider_projection_memory_chars', self::DEFAULT_MEMORY_CHARS),
            self::DEFAULT_MEMORY_CHARS,
            self::MIN_MEMORY_CHARS,
            self::MAX_MEMORY_CHARS,
        );
    }

    public function limit(mixed $value, int $default, int $min, int $max): int
    {
        if (! is_numeric($value)) {
            $value = $default;
        }

        return max($min, min($max, (int) $value));
    }
}
