<?php

declare(strict_types=1);

namespace App\Services\Ai\Mission\Support;

final class MissionPromptTokenizer
{
    /**
     * Tokenize a raw human prompt for ambiguity heuristics.
     *
     * This intentionally preserves the historical scorer contract: whitespace
     * splits tokens, then common edge punctuation is stripped.
     *
     * @return list<string>
     */
    public static function promptWords(string $rawPrompt): array
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

    /**
     * Tokenize objective text into lower-case semantic words.
     *
     * This is intentionally stricter than promptWords(): punctuation and other
     * separators are boundaries, while Unicode letters and numbers are kept.
     *
     * @return list<string>
     */
    public static function semanticWords(string $text): array
    {
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY);

        return $tokens === false ? [] : array_values($tokens);
    }
}
