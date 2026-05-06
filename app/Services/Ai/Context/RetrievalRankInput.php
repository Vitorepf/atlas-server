<?php

namespace App\Services\Ai\Context;

final class RetrievalRankInput
{
    public const DEFAULT_SESSION_TOP_N = 3;

    public const MAX_SESSION_TOP_N = 10;

    public const MAX_PROMPT_SESSION_TOP_N = 5;

    public function sessionTopN(mixed $value = null): int
    {
        return $this->limit($value, self::DEFAULT_SESSION_TOP_N, self::MAX_SESSION_TOP_N);
    }

    public function promptSessionTopN(mixed $value = null): int
    {
        return $this->limit($value, self::DEFAULT_SESSION_TOP_N, self::MAX_PROMPT_SESSION_TOP_N);
    }

    private function limit(mixed $value, int $default, int $max): int
    {
        if (! is_numeric($value)) {
            $value = $default;
        }

        return max(1, min($max, (int) $value));
    }
}
