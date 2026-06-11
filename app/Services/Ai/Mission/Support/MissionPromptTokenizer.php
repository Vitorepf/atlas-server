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
        $tokens = [];

        foreach (self::whitespaceTokens(strtolower($rawPrompt)) as $rawToken) {
            $token = trim($rawToken, " \t\n\r\0\x0B.,;:!?\"'()[]{}");

            if ($token !== '') {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }

    /**
     * Split text on whitespace only, preserving token casing and punctuation.
     *
     * @return list<string>
     */
    public static function whitespaceTokens(string $text): array
    {
        $text = trim($text);

        if ($text === '') {
            return [];
        }

        $tokens = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);

        return $tokens === false ? [] : array_values($tokens);
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
