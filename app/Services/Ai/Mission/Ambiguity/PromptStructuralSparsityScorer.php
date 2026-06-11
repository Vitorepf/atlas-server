<?php

declare(strict_types=1);

namespace App\Services\Ai\Mission\Ambiguity;

use App\Services\Ai\Mission\Support\MissionPromptTokenizer;

final class PromptStructuralSparsityScorer
{
    private const PENALTY_SINGLE_WORD = 0.6;

    private const PENALTY_TRAILING_ELLIPSIS = 0.5;

    private const PENALTY_VERY_SHORT = 0.3;

    private const SHORT_TOKEN_THRESHOLD = 3;

    private const UNICODE_ELLIPSIS = "\u{2026}";

    /**
     * Score the structural sparsity of a raw prompt in the range [0.0, 1.0].
     *
     * The score is the MAX (non-additive) of three independent structural
     * penalties, so the strongest signal dominates rather than accumulating:
     *  - single-word command (exactly 1 token after trim) => 0.6
     *  - trailing ellipsis ('...' or unicode ellipsis at end) => 0.5
     *  - very short prompt (<= 3 tokens) => 0.3
     */
    public function sparsity(string $rawPrompt): float
    {
        $trimmed = trim($rawPrompt);
        $tokenCount = $this->tokenCount($trimmed);

        $singleWordPenalty = $tokenCount === 1 ? self::PENALTY_SINGLE_WORD : 0.0;
        $ellipsisPenalty = $this->hasTrailingEllipsis($trimmed) ? self::PENALTY_TRAILING_ELLIPSIS : 0.0;
        $veryShortPenalty = ($tokenCount >= 1 && $tokenCount <= self::SHORT_TOKEN_THRESHOLD)
            ? self::PENALTY_VERY_SHORT
            : 0.0;

        return max($singleWordPenalty, $ellipsisPenalty, $veryShortPenalty);
    }

    private function tokenCount(string $trimmed): int
    {
        return count(MissionPromptTokenizer::whitespaceTokens($trimmed));
    }

    private function hasTrailingEllipsis(string $trimmed): bool
    {
        if ($trimmed === '') {
            return false;
        }

        return str_ends_with($trimmed, '...')
            || str_ends_with($trimmed, self::UNICODE_ELLIPSIS);
    }
}
