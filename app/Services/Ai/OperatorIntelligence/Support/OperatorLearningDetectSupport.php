<?php

declare(strict_types=1);

namespace App\Services\Ai\OperatorIntelligence\Support;

use Illuminate\Support\Str;

/**
 * Pure normalize + heuristic family/taxonomy/confidence helpers for passive signal detection.
 */
final class OperatorLearningDetectSupport
{
    /**
     * @var array<string, list<string>>
     */
    public const FAMILY_PATTERNS = [
        'preference' => [
            '/\b(eu\s+)?prefiro\b/u',
            '/\bminha preferencia\b/u',
            '/\b(eu\s+)?gosto\b/u',
            '/\b(eu\s+)?nao gosto\b/u',
        ],
        'memory_request' => [
            '/\blembre( se)?\b/u',
            '/\bguarde\b/u',
            '/\baprenda\b/u',
            '/\bmemorize\b/u',
        ],
        'recurring_instruction' => [
            '/\bda proxima vez\b/u',
            '/\bde agora em diante\b/u',
            '/\bsempre que\b/u',
            '/\bquando eu pedir\b/u',
            '/\bquando eu falar\b/u',
        ],
        'boundary' => [
            '/\b(nunca|jamais)\b/u',
            '/\bnao faca mais\b/u',
            '/\bpara de\b/u',
            '/\bpare de\b/u',
            '/\bnao quero que (voce|o atlas)\b/u',
            '/\bnao mexa\b/u',
        ],
    ];

    /**
     * @return list<string>
     */
    public static function sentences(string $input): array
    {
        $parts = preg_split('/(?<=[.!?;])\s+|\n+/u', $input) ?: [];

        return array_values(array_filter(array_map(
            static fn (string $part): string => trim($part),
            $parts,
        ), static fn (string $part): bool => $part !== ''));
    }

    public static function normalizeForMatch(string $value): string
    {
        $value = Str::lower(Str::ascii($value));
        $value = preg_replace('/[^\pL\pN\s]+/u', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }

    public static function matchingSentence(string $input): ?string
    {
        $input = trim(preg_replace('/\s+/', ' ', $input) ?? '');
        if ($input === '' || mb_strlen($input) < 12) {
            return null;
        }

        foreach (self::sentences($input) as $sentence) {
            $normalized = self::normalizeForMatch($sentence);
            if (self::isExplicitLearningSignal($normalized)) {
                return Str::limit(trim($sentence), 1000, '');
            }
        }

        $normalized = self::normalizeForMatch($input);
        if (self::isExplicitLearningSignal($normalized)) {
            return Str::limit($input, 1000, '');
        }

        return null;
    }

    public static function isExplicitLearningSignal(string $normalized): bool
    {
        return self::matchedFamily($normalized) !== 'none';
    }

    public static function matchedFamily(string $normalized): string
    {
        foreach (self::FAMILY_PATTERNS as $family => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $normalized) === 1) {
                    return $family;
                }
            }
        }

        return 'none';
    }

    public static function signalKind(string $normalized): string
    {
        $family = self::matchedFamily($normalized);
        if ($family === 'boundary') {
            return 'operator_boundary';
        }
        if ($family === 'recurring_instruction' || str_contains($normalized, 'pergunta') || str_contains($normalized, 'status')) {
            return 'collaboration_preference';
        }

        return 'operator_preference';
    }

    public static function taxonomy(string $normalized, string $kind): string
    {
        if ($kind === 'operator_boundary') {
            return 'OP-140';
        }

        foreach (['resposta', 'status', 'tom', 'curt', 'objetiv', 'diret', 'detalh', 'explic'] as $needle) {
            if (str_contains($normalized, $needle)) {
                return 'COL-156';
            }
        }

        foreach (['pergunta', 'clarifica', 'aprov', 'autonom', 'bloque'] as $needle) {
            if (str_contains($normalized, $needle)) {
                return 'COL-157';
            }
        }

        foreach (['gosto', 'prefiro', 'sobre mim', 'meu jeito', 'minha forma'] as $needle) {
            if (str_contains($normalized, $needle)) {
                return 'OP-124';
            }
        }

        return 'OP-071';
    }

    public static function confidence(string $normalized, string $kind): float
    {
        if ($kind === 'operator_boundary') {
            return 0.92;
        }

        foreach (['prefiro', 'sempre que', 'de agora em diante', 'da proxima vez', 'quando eu pedir'] as $needle) {
            if (str_contains($normalized, $needle)) {
                return 0.91;
            }
        }

        foreach (['lembre', 'guarde', 'aprenda', 'memorize'] as $needle) {
            if (str_contains($normalized, $needle)) {
                return 0.86;
            }
        }

        return 0.78;
    }

    public static function scopeType(string $normalized): string
    {
        foreach (['neste projeto', 'nesse projeto', 'para este repo', 'para esse repo', 'nesta conversa', 'nessa conversa'] as $needle) {
            if (str_contains($normalized, $needle)) {
                return 'project';
            }
        }

        return 'global';
    }

    public static function profileKey(string $normalized, string $taxonomy, string $kind): string
    {
        $prefix = match (true) {
            $kind === 'operator_boundary' => 'boundaries',
            str_starts_with($taxonomy, 'COL-') => 'collaboration',
            default => 'operator',
        };

        $slug = Str::slug(Str::limit($normalized, 80, ''), '_');
        $slug = $slug !== '' ? $slug : strtolower(str_replace('-', '_', $taxonomy));

        return $prefix.'.'.$slug;
    }

    /**
     * Passive-detector effect table (keeps COL-156 response_style; other COL-* collaboration_rule).
     * Distinct from CandidateSupport::effectForTaxonomy which maps all COL-* → response_style.
     */
    public static function effect(string $taxonomy, string $kind): string
    {
        if ($kind === 'operator_boundary') {
            return 'do_not_do';
        }
        if ($taxonomy === 'COL-156') {
            return 'response_style';
        }
        if (str_starts_with($taxonomy, 'COL-')) {
            return 'collaboration_rule';
        }

        return 'context_hint';
    }
}
