<?php

namespace App\Services\Ai\Kernel\Mcp;

final class OpenBrainMcpInput
{
    public const DEFAULT_CODE_LIMIT = 20;

    public const MAX_CODE_LIMIT = 100;

    public const DEFAULT_DOCS_LIMIT = 10;

    public const MAX_DOCS_LIMIT = 50;

    public const DEFAULT_RECENT_CHANGES_LIMIT = 50;

    public const MAX_RECENT_CHANGES_LIMIT = 200;

    public const DEFAULT_DECISION_LIMIT = 5;

    public const MAX_DECISION_LIMIT = 20;

    public const DEFAULT_SYMBOLS_LIMIT = 50;

    public const MAX_SYMBOLS_LIMIT = 200;

    public const DEFAULT_CONTEXT_MEMORY_LIMIT = 5;

    public const MAX_CONTEXT_MEMORY_LIMIT = 20;

    public const DEFAULT_CONTEXT_CODE_LIMIT = 10;

    public const MAX_CONTEXT_CODE_LIMIT = 50;

    public const DEFAULT_CONTEXT_DOCS_LIMIT = 5;

    public const MAX_CONTEXT_DOCS_LIMIT = 20;

    public function limit(mixed $value, int $default, int $max): int
    {
        if (! is_numeric($value)) {
            return $this->limit($default, $default, $max);
        }

        return max(1, min($max, (int) $value));
    }

    public function codeLimit(mixed $value): int
    {
        return $this->limit($value, self::DEFAULT_CODE_LIMIT, self::MAX_CODE_LIMIT);
    }

    public function docsLimit(mixed $value): int
    {
        return $this->limit($value, self::DEFAULT_DOCS_LIMIT, self::MAX_DOCS_LIMIT);
    }

    public function recentChangesLimit(mixed $value): int
    {
        return $this->limit($value, self::DEFAULT_RECENT_CHANGES_LIMIT, self::MAX_RECENT_CHANGES_LIMIT);
    }

    public function decisionLimit(mixed $value): int
    {
        return $this->limit($value, self::DEFAULT_DECISION_LIMIT, self::MAX_DECISION_LIMIT);
    }

    public function symbolsLimit(mixed $value): int
    {
        return $this->limit($value, self::DEFAULT_SYMBOLS_LIMIT, self::MAX_SYMBOLS_LIMIT);
    }

    public function contextMemoryLimit(mixed $value): int
    {
        return $this->limit($value, self::DEFAULT_CONTEXT_MEMORY_LIMIT, self::MAX_CONTEXT_MEMORY_LIMIT);
    }

    public function contextCodeLimit(mixed $value): int
    {
        return $this->limit($value, self::DEFAULT_CONTEXT_CODE_LIMIT, self::MAX_CONTEXT_CODE_LIMIT);
    }

    public function contextDocsLimit(mixed $value): int
    {
        return $this->limit($value, self::DEFAULT_CONTEXT_DOCS_LIMIT, self::MAX_CONTEXT_DOCS_LIMIT);
    }
}
