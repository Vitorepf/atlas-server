<?php

declare(strict_types=1);

namespace App\Services\Ai\OperatorIntelligence\Support;

use Illuminate\Support\Str;

/**
 * Pure quote-grounding / hedge / breadth / privacy gates for operator comprehension (full-pass peel).
 */
final class OperatorComprehensionGateSupport
{
    public const MIN_QUOTE_CHARS = 20;

    /** @var array<string, float> tier → numeric confidence */
    public const TIER_CONFIDENCE = ['explicit' => 0.9, 'repeated' => 0.8, 'single_inference' => 0.55];

    /** @var list<string> */
    public const HEDGE_TOKENS = [
        'as vezes', 'acho que', 'eu acho', 'talvez', 'nao sei', 'sei la', 'pode ser',
        'quem sabe', 'imagino que', 'suponho', 'me parece', 'parece que', 'meio que',
        'nao tenho certeza', 'sem certeza', 'nao tenho bem certeza',
        'i think', 'i guess', 'maybe', 'perhaps', 'not sure', 'kind of', 'sort of',
        'i suppose', 'probably',
    ];

    /** @var list<string> */
    public const UNIVERSAL_TOKENS = [
        'sempre', 'todos', 'todas', 'qualquer', 'nunca', 'jamais', 'para sempre', 'em todos',
        'always', 'every', 'everything', 'all', 'never', 'forever',
    ];

    /** @var list<string> */
    public const MOMENTARY_MARKERS = [
        'queria testar', 'quero testar', 'vou testar', 'testar uma', 'um pouco', 'nesse caso',
        'neste caso', 'nesse momento', 'desta vez', 'dessa vez', 'so dessa vez', 'por agora',
        'so queria', 'so quero', 'this time', 'for now', 'just now', 'right now', 'in this case',
        'just wanted', 'wanted to test', 'a bit', 'one off', 'just this once',
    ];

    /** @var array<string, int> */
    public const PRIVACY_RANK = ['normal' => 0, 'private' => 1, 'sensitive' => 2, 'secret' => 3];

    public static function fold(string $s): string
    {
        $s = Str::ascii(mb_strtolower($s));

        return trim(preg_replace('/\s+/', ' ', $s) ?? '');
    }

    public static function quoteIsGrounded(string $quote, string $source, int $minQuoteChars = self::MIN_QUOTE_CHARS): bool
    {
        $q = self::fold($quote);
        if (mb_strlen($q) < $minQuoteChars) {
            return false;
        }

        return str_contains(self::fold($source), $q);
    }

    /**
     * @param  list<string>|null  $universalTokens
     * @param  list<string>|null  $momentaryMarkers
     */
    public static function overGeneralizes(
        string $claim,
        string $quote,
        ?array $universalTokens = null,
        ?array $momentaryMarkers = null,
    ): bool {
        $universalTokens ??= self::UNIVERSAL_TOKENS;
        $momentaryMarkers ??= self::MOMENTARY_MARKERS;
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
     * @param  list<string>|null  $momentaryMarkers
     */
    public static function quoteIsVisiblyScoped(string $quote, ?array $momentaryMarkers = null): bool
    {
        $momentaryMarkers ??= self::MOMENTARY_MARKERS;
        $q = ' '.self::fold($quote);
        foreach ($momentaryMarkers as $m) {
            if (str_contains($q, ' '.$m)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>|null  $hedgeTokens
     */
    public static function isHedged(string $text, ?array $hedgeTokens = null): bool
    {
        $hedgeTokens ??= self::HEDGE_TOKENS;
        $t = ' '.self::fold($text);
        foreach ($hedgeTokens as $h) {
            if (str_contains($t, ' '.$h)) {
                return true;
            }
        }

        return false;
    }

    /** Return the MORE restrictive of two privacy classes (escalate-only). */
    public static function raisePrivacy(string $a, string $b): string
    {
        return (self::PRIVACY_RANK[$a] ?? 0) >= (self::PRIVACY_RANK[$b] ?? 0) ? $a : $b;
    }

    /**
     * Max privacy across a list of classes (escalate-only).
     *
     * @param  list<string>  $classes
     */
    public static function raisePrivacyList(array $classes): string
    {
        $max = 'normal';
        foreach ($classes as $c) {
            $max = self::raisePrivacy($max, (string) $c);
        }

        return $max;
    }

    public static function signalKind(string $taxonomyItemId, string $inferenceType): string
    {
        if (Str::startsWith(strtoupper($taxonomyItemId), 'COL-')) {
            return 'collaboration_preference';
        }

        return $inferenceType === 'implicit' ? 'operator_inference' : 'operator_preference';
    }

    public static function normalizeTier(string $tier, string $fallback = 'single_inference'): string
    {
        return isset(self::TIER_CONFIDENCE[$tier]) ? $tier : $fallback;
    }

    public static function normalizeScopeType(string $scopeType): string
    {
        return in_array($scopeType, ['global', 'project', 'session', 'thread'], true) ? $scopeType : 'global';
    }

    public static function normalizePrivacyClass(string $privacy): string
    {
        return in_array($privacy, ['normal', 'private', 'sensitive', 'secret'], true) ? $privacy : 'normal';
    }
}
