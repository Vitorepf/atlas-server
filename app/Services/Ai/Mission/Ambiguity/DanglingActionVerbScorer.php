<?php

declare(strict_types=1);

namespace App\Services\Ai\Mission\Ambiguity;

final class DanglingActionVerbScorer
{
    /**
     * Leading imperative action verbs that, when they open a prompt with no
     * concrete object behind them, signal a dangling (under-specified) request.
     *
     * @var list<string>
     */
    private const LEADING_ACTION_VERBS = [
        // PT
        'crie',
        'faca',
        'analise',
        'corrija',
        'implemente',
        'melhore',
        'otimize',
        // EN
        'create',
        'fix',
        'build',
        'analyze',
        'improve',
        'make',
    ];

    private const SCORE_BARE_VERB = 1.0;

    private const SCORE_SINGLE_TRAILING = 0.5;

    private const SCORE_GROUNDED = 0.0;

    public function danglingScore(string $rawPrompt): float
    {
        $tokens = $this->tokenize($rawPrompt);

        if ($tokens === []) {
            return self::SCORE_GROUNDED;
        }

        $leadToken = $tokens[0];

        if (! $this->isLeadingActionVerb($leadToken)) {
            return self::SCORE_GROUNDED;
        }

        $trailingContentTokens = count($tokens) - 1;

        if ($trailingContentTokens <= 0) {
            return self::SCORE_BARE_VERB;
        }

        if ($trailingContentTokens === 1) {
            return self::SCORE_SINGLE_TRAILING;
        }

        return self::SCORE_GROUNDED;
    }

    private function isLeadingActionVerb(string $token): bool
    {
        return in_array($token, self::LEADING_ACTION_VERBS, true);
    }

    /**
     * @return list<string>
     */
    private function tokenize(string $rawPrompt): array
    {
        $normalized = strtolower(trim($rawPrompt));

        if ($normalized === '') {
            return [];
        }

        $rawTokens = preg_split('/\s+/', $normalized) ?: [];

        $tokens = [];

        foreach ($rawTokens as $rawToken) {
            $token = trim($rawToken, " \t\n\r\0\x0B.,;:!?\"'()[]{}");

            if ($token !== '') {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }
}
