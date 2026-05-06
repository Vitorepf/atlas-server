<?php

namespace App\Services\Ai\Memory;

final class MemoryRecallInput
{
    public const DEFAULT_RECALL_LIMIT = 10;

    public const MAX_RECALL_LIMIT = 50;

    public const MAX_REGISTRY_CANDIDATE_LIMIT = 100;

    public const MAX_VERBATIM_CANDIDATE_LIMIT = 50;

    public const MAX_SEMANTIC_CANDIDATE_LIMIT = 50;

    public const DEFAULT_BUDGET_CHARS = 2400;

    public const MIN_BUDGET_CHARS = 120;

    public const MAX_BUDGET_CHARS = 12000;

    public const DEFAULT_ITEM_CHARS = 360;

    public const MIN_ITEM_CHARS = 80;

    public const MAX_ITEM_CHARS = 3000;

    public const DEFAULT_REGISTRY_EXCERPT_CHARS = 900;

    public const MIN_REGISTRY_EXCERPT_CHARS = 120;

    public const MAX_REGISTRY_EXCERPT_CHARS = 5000;

    public function recallLimit(mixed $value = null): int
    {
        return $this->limit(
            $value ?? config('atlas.ai.memory_recall_limit', self::DEFAULT_RECALL_LIMIT),
            self::DEFAULT_RECALL_LIMIT,
            1,
            self::MAX_RECALL_LIMIT,
        );
    }

    public function registryCandidateLimit(mixed $value = null, ?int $recallLimit = null): int
    {
        $default = max(($recallLimit ?? $this->recallLimit()) * 3, 12);

        return $this->limit($value, $default, 0, self::MAX_REGISTRY_CANDIDATE_LIMIT);
    }

    public function verbatimCandidateLimit(mixed $value = null, ?int $recallLimit = null): int
    {
        $default = max($recallLimit ?? $this->recallLimit(), 4);

        return $this->limit($value, $default, 0, self::MAX_VERBATIM_CANDIDATE_LIMIT);
    }

    public function semanticCandidateLimit(mixed $value = null, ?int $recallLimit = null): int
    {
        $default = max($recallLimit ?? $this->recallLimit(), 5);

        return $this->limit($value, $default, 0, self::MAX_SEMANTIC_CANDIDATE_LIMIT);
    }

    public function budgetChars(mixed $value = null): int
    {
        return $this->limit(
            $value ?? config('atlas.ai.memory_recall_budget_chars', self::DEFAULT_BUDGET_CHARS),
            self::DEFAULT_BUDGET_CHARS,
            self::MIN_BUDGET_CHARS,
            self::MAX_BUDGET_CHARS,
        );
    }

    public function itemChars(mixed $value = null): int
    {
        return $this->limit(
            $value ?? config('atlas.ai.memory_recall_item_chars', self::DEFAULT_ITEM_CHARS),
            self::DEFAULT_ITEM_CHARS,
            self::MIN_ITEM_CHARS,
            self::MAX_ITEM_CHARS,
        );
    }

    public function registryExcerptChars(mixed $value = null): int
    {
        return $this->limit(
            $value ?? config('atlas.ai.memory_registry_excerpt_chars', self::DEFAULT_REGISTRY_EXCERPT_CHARS),
            self::DEFAULT_REGISTRY_EXCERPT_CHARS,
            self::MIN_REGISTRY_EXCERPT_CHARS,
            self::MAX_REGISTRY_EXCERPT_CHARS,
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
