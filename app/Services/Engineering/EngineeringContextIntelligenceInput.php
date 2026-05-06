<?php

namespace App\Services\Engineering;

final class EngineeringContextIntelligenceInput
{
    public const DEFAULT_KNOWLEDGE_LIMIT = 50;

    public const MAX_KNOWLEDGE_LIMIT = 200;

    public const DEFAULT_CODE_LIMIT = 50;

    public const MAX_CODE_LIMIT = 500;

    public const DEFAULT_EVIDENCE_HISTORY_LIMIT = 100;

    public const MAX_EVIDENCE_HISTORY_LIMIT = 200;

    public function knowledgeLimit(mixed $value = null): int
    {
        return $this->limit($value, self::DEFAULT_KNOWLEDGE_LIMIT, 1, self::MAX_KNOWLEDGE_LIMIT);
    }

    public function codeLimit(mixed $value = null): int
    {
        return $this->limit($value, self::DEFAULT_CODE_LIMIT, 1, self::MAX_CODE_LIMIT);
    }

    public function evidenceHistoryLimit(mixed $value = null): int
    {
        return $this->limit($value, self::DEFAULT_EVIDENCE_HISTORY_LIMIT, 1, self::MAX_EVIDENCE_HISTORY_LIMIT);
    }

    public function limit(mixed $value, int $default, int $min, int $max): int
    {
        if (! is_numeric($value)) {
            $value = $default;
        }

        return max($min, min($max, (int) $value));
    }
}
