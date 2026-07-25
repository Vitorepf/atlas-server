<?php

declare(strict_types=1);

namespace App\Services\Ai\OperatorIntelligence\Support;

use Illuminate\Support\Str;

/**
 * Pure quote-grounding / hedge / breadth gates for operator comprehension (full-pass peel).
 */
final class OperatorComprehensionGateSupport
{
    public static function fold(string $s): string
    {
        $s = Str::ascii(mb_strtolower($s));

        return trim(preg_replace('/\s+/', ' ', $s) ?? '');
    }

    public static function quoteIsGrounded(string $quote, string $source, int $minQuoteChars): bool
    {
        $q = self::fold($quote);
        if (mb_strlen($q) < $minQuoteChars) {
            return false;
        }

        return str_contains(self::fold($source), $q);
    }

    /**
     * @param  list<string>  $universalTokens
     * @param  list<string>  $momentaryMarkers
     */
    public static function overGeneralizes(
        string $claim,
        string $quote,
        array $universalTokens,
        array $momentaryMarkers,
    ): bool {
        $c = ' '.self::fold($claim);
        $q = ' '.self::fold($quote);
        $universalizes = false;
        foreach ($universalTokens as $t) {
            if (str_contains($c, ' '.$t)) {
                $universalizes = true;
                if (! str_contains($q, ' '.$t)) {
                    return true;
                }
            }
        }

        return $universalizes && self::quoteIsVisiblyScoped($quote, $momentaryMarkers);
    }

    /**
     * @param  list<string>  $momentaryMarkers
     */
    public static function quoteIsVisiblyScoped(string $quote, array $momentaryMarkers): bool
    {
        $q = ' '.self::fold($quote);
        foreach ($momentaryMarkers as $m) {
            if (str_contains($q, ' '.$m)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $hedgeTokens
     */
    public static function isHedged(string $text, array $hedgeTokens): bool
    {
        $t = ' '.self::fold($text);
        foreach ($hedgeTokens as $h) {
            if (str_contains($t, ' '.$h)) {
                return true;
            }
        }

        return false;
    }
}
