<?php

namespace App\Services\Ai\Context;

final class ContextPackMemoryInput
{
    public const DEFAULT_MEMORY_REGISTRY_LIMIT = 8;

    public const MAX_MEMORY_REGISTRY_LIMIT = 50;

    public const DEFAULT_VERBATIM_RECALL_LIMIT = 4;

    public const MAX_VERBATIM_RECALL_LIMIT = 25;

    public const DEFAULT_VERBATIM_RECALL_BUDGET_CHARS = 1600;

    public const MIN_VERBATIM_RECALL_BUDGET_CHARS = 120;

    public const MAX_VERBATIM_RECALL_BUDGET_CHARS = 8000;

    public const DEFAULT_VERBATIM_RECALL_ITEM_CHARS = 600;

    public const MIN_VERBATIM_RECALL_ITEM_CHARS = 80;

    public const MAX_VERBATIM_RECALL_ITEM_CHARS = 3000;

    public const DEFAULT_MEMORY_REGISTRY_EXCERPT_CHARS = 900;

    public const MIN_MEMORY_REGISTRY_EXCERPT_CHARS = 120;

    public const MAX_MEMORY_REGISTRY_EXCERPT_CHARS = 5000;

    public function memoryRegistryLimit(mixed $value = null): int
    {
        return $this->limit(
            $value ?? config('atlas.ai.memory_registry_limit', self::DEFAULT_MEMORY_REGISTRY_LIMIT),
            self::DEFAULT_MEMORY_REGISTRY_LIMIT,
            0,
            self::MAX_MEMORY_REGISTRY_LIMIT,
        );
    }

    public function verbatimRecallLimit(mixed $value = null): int
    {
        return $this->limit(
            $value ?? config('atlas.ai.verbatim_recall_limit', self::DEFAULT_VERBATIM_RECALL_LIMIT),
            self::DEFAULT_VERBATIM_RECALL_LIMIT,
            0,
            self::MAX_VERBATIM_RECALL_LIMIT,
        );
    }

    public function verbatimRecallBudgetChars(mixed $value = null): int
    {
        return $this->limit(
            $value ?? config('atlas.ai.verbatim_recall_budget_chars', self::DEFAULT_VERBATIM_RECALL_BUDGET_CHARS),
            self::DEFAULT_VERBATIM_RECALL_BUDGET_CHARS,
            self::MIN_VERBATIM_RECALL_BUDGET_CHARS,
            self::MAX_VERBATIM_RECALL_BUDGET_CHARS,
        );
    }

    public function verbatimRecallItemChars(mixed $value = null): int
    {
        return $this->limit(
            $value ?? config('atlas.ai.verbatim_recall_item_chars', self::DEFAULT_VERBATIM_RECALL_ITEM_CHARS),
            self::DEFAULT_VERBATIM_RECALL_ITEM_CHARS,
            self::MIN_VERBATIM_RECALL_ITEM_CHARS,
            self::MAX_VERBATIM_RECALL_ITEM_CHARS,
        );
    }

    public function memoryRegistryExcerptChars(mixed $value = null): int
    {
        return $this->limit(
            $value ?? config('atlas.ai.memory_registry_excerpt_chars', self::DEFAULT_MEMORY_REGISTRY_EXCERPT_CHARS),
            self::DEFAULT_MEMORY_REGISTRY_EXCERPT_CHARS,
            self::MIN_MEMORY_REGISTRY_EXCERPT_CHARS,
            self::MAX_MEMORY_REGISTRY_EXCERPT_CHARS,
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
